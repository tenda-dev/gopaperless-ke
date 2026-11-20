<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\BackgroundJob;

use OCA\Libresign\BackgroundJob\Reconciliation\CompositeCursor;
use OCA\Libresign\BackgroundJob\Reconciliation\ReconciliationCursorStore;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Enum\ProductCode;
use OCA\Libresign\Enum\SettlementSource;
use OCA\Libresign\Exception\InsufficientEntitlementException;
use OCA\Libresign\Service\Sponsorship\SignerResolverService;
use OCA\Libresign\Service\Sponsorship\SigningSettlementService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Recovers entitlement settlement for signing requests whose signing event
 * settlement was missed or unsuccessful.
 *
 * All consumption is delegated to SigningSettlementService::settle(), keeping
 * the reconciliation path subject to the same idempotency guarantees as the
 * primary settlement workflow.
 *
 * The scans combine timestamp and ID cursors because signing timestamps and
 * request IDs are not correlated. Additional boundary and recovery sweeps
 * ensure records are not permanently skipped when they arrive behind a cursor
 * or fall outside the normal forward-scanning window.
 */
class ReconcileUnconsumedSignaturesJob extends TimedJob {
	private const CLASS_CONSUMED = 'consumed';
	private const CLASS_DEAD_LETTERED = 'dead_lettered';
	private const CLASS_BACKING_OFF = 'backing_off';
	private const CLASS_ACTIONABLE = 'actionable';

	/**
	 * Width of the recent "safety window" re-examined on every forward run so a
	 * record that becomes eligible near the window's lower edge is caught
	 * promptly rather than waiting for the full sweep.
	 */
	private const SAFETY_LAG = 86400; // 24h

	/**
	 * Keeps the window cursor behind timestamps that may still be committing.
	 * The horizon must exceed worst-case signing commit latency and clock skew,
	 * since signing timestamps may originate from a different machine.
	 */
	private const STABILITY_LAG = 1800; // 30m (activation decision; still validate against staging per note above)

	private const PAGE_SIZE = 200;

	// Reserved per-pass floors prevent one scan from starving the others.
	// Remaining capacity is distributed in the same priority order.
	private const SCAN_BUDGET_ROWS = 2000;
	private const ACTIONABLE_BUDGET = 100;
	private const FLOOR_2B_ROWS = 400;
	private const FLOOR_2A_ROWS = 800; // >= PAGE_SIZE: at least one page of window progress per run
	private const FLOOR_S1_ROWS = 400;
	private const FLOOR_2B_ACT = 20;
	private const FLOOR_2A_ACT = 40;
	private const FLOOR_S1_ACT = 20;

	private const RECOVERY_BUDGET_ROWS = 500;
	private const RECOVERY_BUDGET_ACT = 25;

	private const MAX_ATTEMPTS = 5;
	private const BACKOFF_BASE = 300; // 5m
	private const BACKOFF_CAP = 86400; // 24h

	// Per-run scratch, reset at the start of each run(). Instance state is safe
	// because a TimedJob invocation is single-threaded; nothing here survives
	// across runs (that is what the persisted cursors are for).
	private int $rowsUsed = 0;
	private int $actUsed = 0;

	public function __construct(
		ITimeFactory $time,
		private SignRequestMapper $signRequestMapper,
		private SignerResolverService $signerResolver,
		private SigningSettlementService $settlementService,
		private ReconciliationCursorStore $cursors,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(600);
		// Backstop work, not latency-critical: never displace time-sensitive jobs.
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	public function run($argument): void {
		$now = $this->time->getTime();
		$this->rowsUsed = 0;
		$this->actUsed = 0;

		$windowStart = $now - self::SAFETY_LAG;
		$stableUpper = $now - self::STABILITY_LAG;

		$this->runForwardScan($now, $windowStart, $stableUpper);
		$this->runRecoverySweepStep($now);
	}

	private function runForwardScan(int $now, int $windowStart, int $stableUpper): void {
		$forwardCheckpoint = $this->cursors->getForwardCheckpoint();

		// Scan 2b cursors are per-run only: the boundary recovery deliberately
		// re-scans from the boundary every run to catch behind-cursor arrivals.
		$cursor2biAfterId = null;
		$done2bi = false;
		$cursor2biiSigned = null;
		$cursor2biiId = null;
		$first2bii = true;
		$done2bii = false;

		// Resume within the sliding safety window. Reset to the lower boundary when
		// the persisted cursor falls outside the current window, including all rows
		// sharing the boundary timestamp.
		$windowCursor = $this->cursors->getWindowCursor();
		if ($windowCursor === null || $windowCursor->signed < $windowStart) {
			$scan2aSigned = $windowStart;
			$scan2aId = 0;
		} else {
			$scan2aSigned = $windowCursor->signed;
			$scan2aId = $windowCursor->id;
		}
		$done2a = false;

		$scan1Signed = $forwardCheckpoint->signed;
		$scan1Id = $forwardCheckpoint->id;
		$done1 = false;
		$contiguousTerminalEnd = $forwardCheckpoint;
		$brokeContiguity = false;

		$run2b = function (int $rowCap, int $actCap) use ($now, $windowStart, $stableUpper, &$cursor2biAfterId, &$done2bi, &$cursor2biiSigned, &$cursor2biiId, &$first2bii, &$done2bii): void {
			$localRows = 0;
			$localAct = 0;

			// The single `signed = windowStart` group, paged by id.
			while (!$done2bi && !$this->capReached($localRows, $localAct, $rowCap, $actCap)) {
				$page = $this->signRequestMapper->findReconcileEqualsPage($windowStart, $cursor2biAfterId, self::PAGE_SIZE);
				if ($page === []) {
					$done2bi = true;
					break;
				}
				foreach ($page as $row) {
					if ($this->capReached($localRows, $localAct, $rowCap, $actCap)) {
						return;
					}
					$localRows++;
					$this->rowsUsed++;
					if ($this->classify($row, $now) === self::CLASS_ACTIONABLE && $this->process($row, $now)) {
						$localAct++;
						$this->actUsed++;
					}
					$cursor2biAfterId = $row->getId();
				}
				if (count($page) < self::PAGE_SIZE) {
					$done2bi = true;
				}
			}

			// Revisit recent signatures whose timestamps may still be changing.
			while (!$done2bii && !$this->capReached($localRows, $localAct, $rowCap, $actCap)) {
				$page = $first2bii
					? $this->signRequestMapper->findReconcileAtOrAbovePage($stableUpper, null, null, self::PAGE_SIZE)
					: $this->signRequestMapper->findReconcileAtOrAbovePage($stableUpper, $cursor2biiSigned, $cursor2biiId, self::PAGE_SIZE);
				$first2bii = false;
				if ($page === []) {
					$done2bii = true;
					break;
				}
				foreach ($page as $row) {
					if ($this->capReached($localRows, $localAct, $rowCap, $actCap)) {
						return;
					}
					$localRows++;
					$this->rowsUsed++;
					if ($this->classify($row, $now) === self::CLASS_ACTIONABLE && $this->process($row, $now)) {
						$localAct++;
						$this->actUsed++;
					}
					$cursor2biiSigned = $this->signedOf($row);
					$cursor2biiId = $row->getId();
				}
				if (count($page) < self::PAGE_SIZE) {
					$done2bii = true;
				}
			}
		};

		$run2a = function (int $rowCap, int $actCap) use ($now, $stableUpper, &$scan2aSigned, &$scan2aId, &$done2a): void {
			$localRows = 0;
			$localAct = 0;
			while (!$done2a && !$this->capReached($localRows, $localAct, $rowCap, $actCap)) {
				// Strict exclusive upper bound: the cursor never enters the
				// volatile band, so it never passes a still-committing timestamp.
				$page = $this->signRequestMapper->findReconcileKeysetPage($scan2aSigned, $scan2aId, $stableUpper, self::PAGE_SIZE);
				if ($page === []) {
					$done2a = true;
					break;
				}
				foreach ($page as $row) {
					if ($this->capReached($localRows, $localAct, $rowCap, $actCap)) {
						return;
					}
					$localRows++;
					$this->rowsUsed++;
					if ($this->classify($row, $now) === self::CLASS_ACTIONABLE && $this->process($row, $now)) {
						$localAct++;
						$this->actUsed++;
					}
					$scan2aSigned = $this->signedOf($row);
					$scan2aId = $row->getId();
				}
				if (count($page) < self::PAGE_SIZE) {
					$done2a = true;
				}
			}
		};

		$run1 = function (int $rowCap, int $actCap) use ($now, $windowStart, &$scan1Signed, &$scan1Id, &$done1, &$contiguousTerminalEnd, &$brokeContiguity): void {
			$localRows = 0;
			$localAct = 0;
			while (!$done1 && !$this->capReached($localRows, $localAct, $rowCap, $actCap)) {
				$page = $this->signRequestMapper->findReconcileKeysetPage($scan1Signed, $scan1Id, $windowStart, self::PAGE_SIZE);
				if ($page === []) {
					$done1 = true;
					break;
				}
				foreach ($page as $row) {
					if ($this->capReached($localRows, $localAct, $rowCap, $actCap)) {
						return;
					}
					$localRows++;
					$this->rowsUsed++;
					$class = $this->classify($row, $now);

					// The durable checkpoint may only advance across a leading run
					// of terminal rows: the first non-terminal row pins it, so a
					// backing-off record is never skipped on a later run.
					if (($class === self::CLASS_CONSUMED || $class === self::CLASS_DEAD_LETTERED) && !$brokeContiguity) {
						$contiguousTerminalEnd = new CompositeCursor($this->signedOf($row), $row->getId());
					} else {
						$brokeContiguity = true;
					}

					if ($class === self::CLASS_ACTIONABLE && $this->process($row, $now)) {
						$localAct++;
						$this->actUsed++;
					}

					// Advance past everything, backing-off included, so one stuck
					// record cannot re-consume this run's budget on later runs.
					$scan1Signed = $this->signedOf($row);
					$scan1Id = $row->getId();
				}
				if (count($page) < self::PAGE_SIZE) {
					$done1 = true;
				}
			}
		};

		// Guarantee baseline progress for each scan before distributing surplus capacity.
		$run2b(self::FLOOR_2B_ROWS, self::FLOOR_2B_ACT);
		$run2a(self::FLOOR_2A_ROWS, self::FLOOR_2A_ACT);
		$run1(self::FLOOR_S1_ROWS, self::FLOOR_S1_ACT);

		// Distribute remaining capacity in priority order without exceeding the shared
		// per-run budgets.
		$run2b(self::SCAN_BUDGET_ROWS, self::ACTIONABLE_BUDGET);
		$run2a(self::SCAN_BUDGET_ROWS, self::ACTIONABLE_BUDGET);
		$run1(self::SCAN_BUDGET_ROWS, self::ACTIONABLE_BUDGET);

		// Persist after the run's work, never on fetch. A crash before this just
		// re-scans from the previous positions next run — settle() is idempotent.
		$this->cursors->setWindowCursor(new CompositeCursor($scan2aSigned, $scan2aId));
		if ($contiguousTerminalEnd->signed > $forwardCheckpoint->signed
			|| ($contiguousTerminalEnd->signed === $forwardCheckpoint->signed && $contiguousTerminalEnd->id > $forwardCheckpoint->id)
		) {
			$this->cursors->setForwardCheckpoint($contiguousTerminalEnd);
		}
	}

	/**
	 * Performs a bounded full-dataset sweep to recover records the forward scans
	 * may miss, including backdated records and records affected by clock changes.
	 * The sweep has its own persisted cursor and restarts from the beginning when
	 * complete, allowing records behind the cursor to be discovered on later runs.
	 */
	private function runRecoverySweepStep(int $now): void {
		$cursor = $this->cursors->getRecoveryCursor();
		$sweep = $this->cursors->getRecoverySweep();

		$startedAt = $sweep['startedAt'];
		if ($cursor === null) {
			$startedAt = $now;
			$this->cursors->setRecoverySweep($sweep['generation'], $now, null);
		}

		$localRows = 0;
		$localAct = 0;
		while ($localRows < self::RECOVERY_BUDGET_ROWS && $localAct < self::RECOVERY_BUDGET_ACT) {
			$page = $this->signRequestMapper->findReconcileKeysetPage(
				$cursor?->signed,
				$cursor?->id,
				null,
				self::PAGE_SIZE,
			);

			if ($page === []) {
				// Record completion separately from an interrupted sweep, then restart so
				// records that became eligible behind the cursor can be found next cycle.
				$this->cursors->setRecoverySweep($sweep['generation'] + 1, $startedAt, $now);
				$this->cursors->setRecoveryCursor(null);
				return;
			}

			foreach ($page as $row) {
				if ($localRows >= self::RECOVERY_BUDGET_ROWS || $localAct >= self::RECOVERY_BUDGET_ACT) {
					$this->cursors->setRecoveryCursor($cursor);
					return;
				}
				$localRows++;
				if ($this->classify($row, $now) === self::CLASS_ACTIONABLE && $this->process($row, $now)) {
					$localAct++;
				}
				$cursor = new CompositeCursor($this->signedOf($row), $row->getId());
			}

			$this->cursors->setRecoveryCursor($cursor);
		}

		$this->cursors->setRecoveryCursor($cursor);
	}

	private function classify(SignRequest $row, int $now): string {
		$metadata = $row->getMetadata() ?? [];

		if (($metadata['entitlement_consumed'] ?? false) === true) {
			return self::CLASS_CONSUMED;
		}
		if (isset($metadata['settlement_dead_letter'])) {
			return self::CLASS_DEAD_LETTERED;
		}

		$attempts = (int)($metadata['settlement_attempts'] ?? 0);
		if ($attempts > 0) {
			$lastAttemptAt = (int)($metadata['settlement_last_attempt_at'] ?? 0);
			if ($now < $lastAttemptAt + $this->backoff($attempts)) {
				return self::CLASS_BACKING_OFF;
			}
		}

		return self::CLASS_ACTIONABLE;
	}

	/**
	 * Resolves the signer and delegates settlement. Unresolved signers are
	 * dead-lettered without attempting settlement.
	 *
	 * Returns whether settlement was attempted so scan budgets count actual
	 * settlement attempts rather than unresolved records.
	 */
	private function process(SignRequest $row, int $now): bool {
		$signRequestId = $row->getId();
		$resolution = $this->signerResolver->resolve($signRequestId);

		if (!$resolution->resolved) {
			// Without a resolved signer, settlement cannot safely determine whose
			// entitlement to consume. Dead-letter until the identity is corrected.
			$this->signRequestMapper->recordSettlementFailure($signRequestId, 'signer_unresolved', true, self::MAX_ATTEMPTS, $now);
			$this->logger->warning('[ReconcileUnconsumedSignaturesJob] Signer unresolved; dead-lettered.', [
				'signRequestId' => $signRequestId,
				'reason' => $resolution->reason,
			]);
			return false;
		}

		try {
			$this->settlementService->settle(
				signRequestId: $signRequestId,
				signerUserId: $resolution->userId,
				productCode: ProductCode::SIGN_DOCUMENT->value,
				source: SettlementSource::RECONCILIATION_JOB,
			);
		} catch (InsufficientEntitlementException $e) {
			// Retrying cannot resolve an entitlement shortage without an account change.
			$this->signRequestMapper->recordSettlementFailure($signRequestId, $e->getFailureClass(), true, self::MAX_ATTEMPTS, $now);
		} catch (\Throwable $e) {
			// Transient: retried with backoff, dead-lettered only after
			// MAX_ATTEMPTS so a persistently failing row cannot hot-loop.
			$this->signRequestMapper->recordSettlementFailure($signRequestId, 'transient', false, self::MAX_ATTEMPTS, $now);
			$this->logger->error('[ReconcileUnconsumedSignaturesJob] Settlement failed; recorded for retry.', [
				'signRequestId' => $signRequestId,
				'exception' => $e,
			]);
		}

		return true;
	}

	private function backoff(int $attempts): int {
		return (int)min(self::BACKOFF_BASE * (2 ** ($attempts - 1)), self::BACKOFF_CAP);
	}

	private function capReached(int $localRows, int $localAct, int $rowCap, int $actCap): bool {
		return $localRows >= $rowCap
			|| $localAct >= $actCap
			|| $this->rowsUsed >= self::SCAN_BUDGET_ROWS
			|| $this->actUsed >= self::ACTIONABLE_BUDGET;
	}

	private function signedOf(SignRequest $row): int {
		$signed = $row->getSigned();

		return $signed?->getTimestamp() ?? 0;
	}
}
