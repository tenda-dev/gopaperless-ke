<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\BackgroundJob;

use OCA\Libresign\BackgroundJob\ReconcileUnconsumedSignaturesJob;
use OCA\Libresign\BackgroundJob\Reconciliation\CompositeCursor;
use OCA\Libresign\BackgroundJob\Reconciliation\ReconciliationCursorStore;
use OCA\Libresign\Db\Entitlement;
use OCA\Libresign\Db\EntitlementMapper;
use OCA\Libresign\Db\EntitlementReservationMapper;
use OCA\Libresign\Db\Product;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Enum\SettlementSource;
use OCA\Libresign\Exception\InsufficientEntitlementException;
use OCA\Libresign\Service\Entitlement\EntitlementReservationService;
use OCA\Libresign\Service\Sponsorship\DTO\SignerResolutionDTO;
use OCA\Libresign\Service\Sponsorship\DTO\SigningSettlementContextDTO;
use OCA\Libresign\Service\Sponsorship\SignerResolverService;
use OCA\Libresign\Service\Sponsorship\SigningSettlementService;
use OCA\Libresign\Service\Sponsorship\SigningSettlementValidationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

if (!interface_exists(\OCP\IDBConnection::class, false)) {
	// The standalone suite stubs OCP from vendor/nextcloud/ocp, whose
	// OCP\IDBConnection references server-side Doctrine DBAL classes that are
	// not installed here, and mocking that interface cascades into loading
	// them. Pre-declare a minimal stand-in (checked without autoloading, so
	// the real interface wins whenever it is already available, e.g. under the
	// server bootstrap) exposing only the transaction methods the settlement
	// service touches.
	eval('namespace OCP; interface IDBConnection { public function beginTransaction(); public function commit(); public function rollBack(); }');
}

/**
 * Drives the job with an in-memory SignRequest dataset emulating the keyset
 * pagination contract of SignRequestMapper, and a real
 * ReconciliationCursorStore backed by a mocked IAppConfig, so cursor
 * advancement can be asserted end to end.
 *
 * SigningSettlementService is final, so the real service is constructed with
 * a mocked validation service: validateCommon() either records the attempt
 * and returns a self-sponsored context (settle() then persists the
 * entitlement_consumed marker on the dataset row for real) or throws the
 * configured exception, mirroring how the job observes settle() outcomes.
 */
final class ReconcileUnconsumedSignaturesJobTest extends TestCase {
	private const NOW = 200000;
	private const SAFETY_LAG = 86400; // mirrors the job constants
	private const STABILITY_LAG = 1800;
	private const PAGE_SIZE = 200;
	private const MAX_ATTEMPTS = 5;

	private int $now = self::NOW;

	/** @var list<SignRequest> dataset, kept ordered by (signed, id) */
	private array $rows = [];

	/** @var list<array{signRequestId: int, signerUserId: string, productCode: string, source: SettlementSource}> */
	private array $settleAttempts = [];

	/** @var list<array{id: int, failureClass: string, needsIntervention: bool, maxAttempts: int, now: int}> */
	private array $recordedFailures = [];

	/** @var array<int, SignerResolutionDTO> per-id overrides; default is resolved */
	private array $resolutions = [];

	/** @var array<int, \Throwable> exception to throw from settle() for a given id */
	private array $settleExceptions = [];

	private SignRequestMapper&MockObject $mapper;
	private ReconciliationCursorStore $cursors;
	private ReconcileUnconsumedSignaturesJob $job;

	public function setUp(): void {
		$this->rows = [];
		$this->settleAttempts = [];
		$this->recordedFailures = [];
		$this->resolutions = [];
		$this->settleExceptions = [];

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$this->mapper = $this->createMock(SignRequestMapper::class);
		$this->mapper->method('findReconcileKeysetPage')
			->willReturnCallback(fn (?int $afterSigned, ?int $afterId, ?int $upperExclusive, int $limit): array => $this->keysetPage($afterSigned, $afterId, $upperExclusive, $limit));
		$this->mapper->method('findReconcileAtOrAbovePage')
			->willReturnCallback(fn (int $fromInclusive, ?int $afterSigned, ?int $afterId, int $limit): array => $this->atOrAbovePage($fromInclusive, $afterSigned, $afterId, $limit));
		$this->mapper->method('findReconcileEqualsPage')
			->willReturnCallback(fn (int $signedValue, ?int $afterId, int $limit): array => $this->equalsPage($signedValue, $afterId, $limit));
		$this->mapper->method('recordSettlementFailure')
			->willReturnCallback(function (int $id, string $failureClass, bool $needsIntervention, int $maxAttempts, int $now): void {
				$this->recordedFailures[] = [
					'id' => $id,
					'failureClass' => $failureClass,
					'needsIntervention' => $needsIntervention,
					'maxAttempts' => $maxAttempts,
					'now' => $now,
				];
			});

		$resolver = $this->createMock(SignerResolverService::class);
		$resolver->method('resolve')
			->willReturnCallback(fn (int $id): SignerResolutionDTO => $this->resolutions[$id] ?? SignerResolutionDTO::resolved('user-' . $id));

		$logger = $this->createMock(LoggerInterface::class);

		$validation = $this->createMock(SigningSettlementValidationService::class);
		$validation->method('validateCommon')
			->willReturnCallback(function (int $signRequestId, string $signerUserId, string $productCode): SigningSettlementContextDTO {
				$this->settleAttempts[] = [
					'signRequestId' => $signRequestId,
					'signerUserId' => $signerUserId,
					'productCode' => $productCode,
					'source' => SettlementSource::RECONCILIATION_JOB,
				];
				if (isset($this->settleExceptions[$signRequestId])) {
					throw $this->settleExceptions[$signRequestId];
				}
				$product = new Product();
				$product->setCode($productCode);
				return new SigningSettlementContextDTO(
					signerUserId: $signerUserId,
					signRequest: $this->rowById($signRequestId),
					product: $product,
				);
			});
		$validation->method('validateSelfSponsored')
			->willReturnCallback(fn (SigningSettlementContextDTO $context, array $excludedEntitlementIds): SigningSettlementContextDTO => $context->withEntitlement(new Entitlement()));

		// SigningSettlementService is final and cannot be doubled; the real
		// instance is safe here because every database touch is a no-op mock.
		$settlement = new SigningSettlementService(
			$this->createMock(IDBConnection::class),
			$validation,
			$this->createMock(EntitlementMapper::class),
			$this->createMock(EntitlementReservationMapper::class),
			$this->createMock(EntitlementReservationService::class),
			$this->mapper,
			$logger,
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$backing = [];
		$appConfig->method('getValueString')
			->willReturnCallback(static function (string $app, string $key, string $default = '') use (&$backing): string {
				return $backing[$key] ?? $default;
			});
		$appConfig->method('setValueString')
			->willReturnCallback(static function (string $app, string $key, string $value) use (&$backing): bool {
				$backing[$key] = $value;
				return true;
			});
		$this->cursors = new ReconciliationCursorStore($appConfig);

		$this->job = new ReconcileUnconsumedSignaturesJob(
			$time,
			$this->mapper,
			$resolver,
			$settlement,
			$this->cursors,
			$logger,
		);
	}

	public function testSettlesActionableRecordsWithReconciliationAttribution(): void {
		$this->addRow(1, 1000, ['entitlement_consumed' => true]);
		$this->addRow(2, 2000);

		$this->job->run(null);

		$this->assertCount(1, $this->settleAttempts, 'the recovery sweep sees the consumed marker written by the forward scan');
		$attempt = $this->settleAttempts[0];
		$this->assertSame(2, $attempt['signRequestId']);
		$this->assertSame('user-2', $attempt['signerUserId']);
		$this->assertSame('SIGN_DOCUMENT', $attempt['productCode']);
		$this->assertSame(SettlementSource::RECONCILIATION_JOB, $attempt['source']);
		$this->assertSame([], $this->recordedFailures);

		$this->assertTrue($this->rowById(2)->getMetadata()['entitlement_consumed'], 'settle() persists the idempotency marker on the row');

		$checkpoint = $this->cursors->getForwardCheckpoint();
		$this->assertSame(1000, $checkpoint->signed, 'checkpoint advances across the consumed row');
		$this->assertSame(1, $checkpoint->id);
	}

	public function testConsumedRecordsAreSkippedIdempotently(): void {
		$this->addRow(1, 1000, ['entitlement_consumed' => true]);

		$this->job->run(null);

		$this->assertSame([], $this->settleAttempts, 'already-consumed markers must not be re-settled');
		$this->assertSame([], $this->recordedFailures);
		$this->assertSame([], $this->resolutions, 'signer resolution is never attempted for consumed rows');
		$checkpoint = $this->cursors->getForwardCheckpoint();
		$this->assertSame(1000, $checkpoint->signed);
		$this->assertSame(1, $checkpoint->id);
	}

	public function testDeadLetteredRecordsAreSkippedAndCountAsTerminal(): void {
		$this->addRow(1, 1000, ['settlement_dead_letter' => self::NOW]);
		$this->addRow(2, 2000);

		$this->job->run(null);

		$this->assertSame([2], $this->settledIds());
		$this->assertSame([], $this->recordedFailures, 'dead-lettered rows must not accumulate more failures');
		$checkpoint = $this->cursors->getForwardCheckpoint();
		$this->assertSame(1000, $checkpoint->signed, 'dead-lettered rows are terminal, so the checkpoint may cross them');
		$this->assertSame(1, $checkpoint->id);
	}

	public function testBackingOffRecordPinsCheckpointAndIsNotRetried(): void {
		$this->addRow(1, 1000, ['entitlement_consumed' => true]);
		// attempts=1 -> backoff 300s; last attempt 100s ago -> still backing off.
		$this->addRow(2, 2000, ['settlement_attempts' => 1, 'settlement_last_attempt_at' => self::NOW - 100]);
		$this->addRow(3, 3000, ['entitlement_consumed' => true]);

		$this->job->run(null);

		$this->assertSame([], $this->settleAttempts);
		$this->assertSame([], $this->recordedFailures);
		$checkpoint = $this->cursors->getForwardCheckpoint();
		$this->assertSame(1000, $checkpoint->signed, 'checkpoint pins at the last row before the backing-off record');
		$this->assertSame(1, $checkpoint->id, 'consumed rows behind a backing-off record must not advance the checkpoint');
	}

	public function testRecordBecomesActionableExactlyWhenExponentialBackoffExpires(): void {
		// Boundaries: backoff(n) = 300 * 2^(n-1); eligible when now >= lastAttempt + backoff.
		$this->addRow(1, 1000, ['settlement_attempts' => 1, 'settlement_last_attempt_at' => self::NOW - 299]); // 300 backoff: one second early
		$this->addRow(2, 2000, ['settlement_attempts' => 2, 'settlement_last_attempt_at' => self::NOW - 600]); // 600 backoff: exactly elapsed
		$this->addRow(3, 3000, ['settlement_attempts' => 4, 'settlement_last_attempt_at' => self::NOW - 2400]); // 2400 backoff: exactly elapsed

		$this->job->run(null);

		$this->assertSame([2, 3], $this->settledIds(), 'only records whose backoff has fully elapsed are settled');
		$this->assertSame([], $this->recordedFailures);
	}

	public function testBackoffIsCappedAtTwentyFourHours(): void {
		// attempts=10 would be 300 * 2^9 = 153600s uncapped; the cap is 86400s.
		$this->addRow(1, 1000, ['settlement_attempts' => 10, 'settlement_last_attempt_at' => self::NOW - 86399]); // one second inside the cap
		$this->addRow(2, 2000, ['settlement_attempts' => 10, 'settlement_last_attempt_at' => self::NOW - 86400]); // cap exactly elapsed

		$this->job->run(null);

		$this->assertSame([2], $this->settledIds());
	}

	public function testUnresolvedSignerIsDeadLetteredWithoutSettlementAttempt(): void {
		$this->addRow(7, 5000);
		$this->resolutions[7] = SignerResolutionDTO::unresolved('email_ambiguous');

		$this->job->run(null);

		$this->assertSame([], $this->settleAttempts, 'settlement must not run when the signer identity is unresolved');
		$this->assertSame(
			array_fill(0, 2, [
				'id' => 7,
				'failureClass' => 'signer_unresolved',
				'needsIntervention' => true,
				'maxAttempts' => self::MAX_ATTEMPTS,
				'now' => self::NOW,
			]),
			$this->recordedFailures,
			'forward scan and recovery sweep each dead-letter the unresolved signer',
		);
	}

	public function testInsufficientEntitlementIsDeadLetteredImmediately(): void {
		$this->addRow(8, 6000);
		$this->settleExceptions[8] = new InsufficientEntitlementException();

		$this->job->run(null);

		$this->assertSame([8, 8], $this->settledIds(), 'settlement was attempted in both scans before dead-lettering');
		$this->assertSame(
			array_fill(0, 2, [
				'id' => 8,
				'failureClass' => 'insufficient_entitlement',
				'needsIntervention' => true,
				'maxAttempts' => self::MAX_ATTEMPTS,
				'now' => self::NOW,
			]),
			$this->recordedFailures,
		);
	}

	public function testTransientFailureIsRecordedForRetryWithoutDeadLetter(): void {
		$this->addRow(9, 7000);
		$this->settleExceptions[9] = new \RuntimeException('database gone away');

		$this->job->run(null);

		$this->assertSame([9, 9], $this->settledIds());
		$this->assertSame(
			array_fill(0, 2, [
				'id' => 9,
				'failureClass' => 'transient',
				'needsIntervention' => false,
				'maxAttempts' => self::MAX_ATTEMPTS,
				'now' => self::NOW,
			]),
			$this->recordedFailures,
			'transient failures keep the record retryable; only the mapper dead-letters after MAX_ATTEMPTS',
		);
	}

	public function testForwardScanRespectsSharedRowBudget(): void {
		// 2000 consumed rows fill the scan budget; the actionable row behind
		// them must not be reached by the forward scan.
		for ($i = 1; $i <= 2000; $i++) {
			$this->addRow($i, 1000 + $i, ['entitlement_consumed' => true]);
		}
		$this->addRow(2001, 3001);

		$this->job->run(null);

		$this->assertSame([], $this->settleAttempts, 'rows beyond the shared 2000-row budget are left for the next run');
		$this->assertSame([], $this->recordedFailures);
		$checkpoint = $this->cursors->getForwardCheckpoint();
		$this->assertSame(3000, $checkpoint->signed, 'the checkpoint reaches exactly the last scanned row');
		$this->assertSame(2000, $checkpoint->id);
		$recovery = $this->cursors->getRecoveryCursor();
		$this->assertNotNull($recovery, 'the recovery sweep keeps its own budget and stops mid-dataset');
		$this->assertSame(1500, $recovery->signed);
		$this->assertSame(500, $recovery->id);
	}

	public function testActionableBudgetCapsSettlementAttemptsPerRun(): void {
		// 100 shared settlement attempts across the forward scans, then 25 more
		// in the recovery sweep; the remaining actionable rows stay untouched.
		for ($i = 1; $i <= 151; $i++) {
			$this->addRow($i, 1000 + $i);
		}

		$this->job->run(null);

		$this->assertSame(range(1, 125), $this->settledIds());
		$this->assertSame([], $this->recordedFailures);
	}

	public function testCheckpointOnlyAdvancesAcrossLeadingTerminalRows(): void {
		$row2 = $this->addRow(1, 1000, ['entitlement_consumed' => true]);
		$this->addRow(2, 2000);
		$this->addRow(3, 3000, ['entitlement_consumed' => true]);

		$this->job->run(null);

		$this->assertSame([2], $this->settledIds());
		$checkpoint = $this->cursors->getForwardCheckpoint();
		$this->assertSame(1000, $checkpoint->signed, 'the trailing consumed row must not advance the checkpoint past the stuck one');
		$this->assertSame(1, $checkpoint->id);

		// The first run already persisted the consumed marker on row 2 through
		// the real settlement path; the checkpoint still refuses to cross the
		// contiguity break it recorded, so advance the marker by hand to
		// simulate the marker having been present from the start.
		$row2->setMetadata(['entitlement_consumed' => true]);

		$this->job->run(null);

		$this->assertSame([2], $this->settledIds(), 'no new settlement attempts on the second run');
		$checkpoint = $this->cursors->getForwardCheckpoint();
		$this->assertSame(3000, $checkpoint->signed, 'once the run starts on a terminal row, the checkpoint crosses the previously stuck range');
		$this->assertSame(3, $checkpoint->id);
	}

	public function testWindowScansCoverBoundaryResumedAndVolatileBands(): void {
		$windowStart = self::NOW - self::SAFETY_LAG;
		$stableUpper = self::NOW - self::STABILITY_LAG;
		$this->assertSame(113600, $windowStart);
		$this->assertSame(198200, $stableUpper);

		$this->addRow(1, $windowStart);       // 2bi: exactly the lower window boundary
		$this->addRow(2, 150000);             // 2a: inside the resumed window band
		$this->addRow(3, $stableUpper + 300); // 2bii: volatile band above the stability lag
		$this->cursors->setWindowCursor(new CompositeCursor(140000, 9));

		$this->job->run(null);

		$this->assertSame(
			[1, 3, 2],
			$this->settledIds(),
			'boundary scan first, then the volatile band, then the resumed window band',
		);
		$windowCursor = $this->cursors->getWindowCursor();
		$this->assertNotNull($windowCursor);
		$this->assertSame(150000, $windowCursor->signed);
		$this->assertSame(2, $windowCursor->id, 'window cursor advances past the resumed band only');
	}

	public function testWindowCursorResetsToWindowStartWhenItFallsBehind(): void {
		$this->cursors->setWindowCursor(new CompositeCursor(1000, 1));

		$this->job->run(null);

		$windowCursor = $this->cursors->getWindowCursor();
		$this->assertNotNull($windowCursor);
		$this->assertSame(self::NOW - self::SAFETY_LAG, $windowCursor->signed);
		$this->assertSame(0, $windowCursor->id, 'a stale cursor restarts at the boundary including all rows at that timestamp');
	}

	public function testRecoverySweepCompletesAndBumpsGeneration(): void {
		$this->job->run(null);

		$sweep = $this->cursors->getRecoverySweep();
		$this->assertSame(1, $sweep['generation']);
		$this->assertSame(self::NOW, $sweep['startedAt']);
		$this->assertSame(self::NOW, $sweep['completedAt']);
		$this->assertNull($this->cursors->getRecoveryCursor(), 'a completed sweep clears its cursor so the next cycle restarts from the beginning');
	}

	public function testRecoverySweepResumesInProgressSweepPreservingStartedAt(): void {
		$this->addRow(6, 1600);
		$this->cursors->setRecoveryCursor(new CompositeCursor(1500, 5));
		$this->cursors->setRecoverySweep(2, 12345, null);

		$this->job->run(null);

		$this->assertSame([6], $this->settledIds(), 'the recovery sweep sees the marker written by the forward scan');
		$sweep = $this->cursors->getRecoverySweep();
		$this->assertSame(3, $sweep['generation']);
		$this->assertSame(12345, $sweep['startedAt'], 'resuming a sweep must not restart its clock');
		$this->assertSame(self::NOW, $sweep['completedAt']);
		$this->assertNull($this->cursors->getRecoveryCursor());
	}

	private function addRow(int $id, int $signed, array $metadata = []): SignRequest {
		$row = new SignRequest();
		$row->setId($id);
		$row->setSigned(new \DateTime('@' . $signed));
		if ($metadata !== []) {
			$row->setMetadata($metadata);
		}
		$this->rows[] = $row;
		return $row;
	}

	private function rowById(int $id): SignRequest {
		foreach ($this->rows as $row) {
			if ($row->getId() === $id) {
				return $row;
			}
		}
		throw new \RuntimeException('row ' . $id . ' not in dataset');
	}

	/** @return list<int> */
	private function settledIds(): array {
		return array_map(fn (array $attempt): int => $attempt['signRequestId'], $this->settleAttempts);
	}

	/**
	 * Emulates SignRequestMapper::findReconcileKeysetPage against the in-memory
	 * dataset: keyset pagination in (signed ASC, id ASC) with an optional
	 * exclusive upper signed bound, over rows with a non-null signed timestamp.
	 *
	 * @return list<SignRequest>
	 */
	private function keysetPage(?int $afterSigned, ?int $afterId, ?int $upperExclusive, int $limit): array {
		$out = [];
		foreach ($this->rows as $row) {
			$signed = $row->getSigned();
			if ($signed === null) {
				continue;
			}
			$timestamp = $signed->getTimestamp();
			if ($afterSigned !== null && $afterId !== null
				&& !($timestamp > $afterSigned || ($timestamp === $afterSigned && $row->getId() > $afterId))) {
				continue;
			}
			if ($upperExclusive !== null && $timestamp >= $upperExclusive) {
				continue;
			}
			$out[] = $row;
			if (count($out) >= $limit) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Emulates SignRequestMapper::findReconcileAtOrAbovePage: the first page is
	 * anchored inclusively at $fromSigned, follow-up pages keyset after the
	 * composite cursor.
	 *
	 * @return list<SignRequest>
	 */
	private function atOrAbovePage(int $fromInclusive, ?int $afterSigned, ?int $afterId, int $limit): array {
		$out = [];
		foreach ($this->rows as $row) {
			$signed = $row->getSigned();
			if ($signed === null) {
				continue;
			}
			$timestamp = $signed->getTimestamp();
			if ($afterSigned !== null && $afterId !== null) {
				if (!($timestamp > $afterSigned || ($timestamp === $afterSigned && $row->getId() > $afterId))) {
					continue;
				}
			} elseif ($timestamp < $fromInclusive) {
				continue;
			}
			$out[] = $row;
			if (count($out) >= $limit) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Emulates SignRequestMapper::findReconcileEqualsPage: one exact signing
	 * timestamp, paged by id.
	 *
	 * @return list<SignRequest>
	 */
	private function equalsPage(int $signedValue, ?int $afterId, int $limit): array {
		$out = [];
		foreach ($this->rows as $row) {
			$signed = $row->getSigned();
			if ($signed === null || $signed->getTimestamp() !== $signedValue) {
				continue;
			}
			if ($afterId !== null && $row->getId() <= $afterId) {
				continue;
			}
			$out[] = $row;
			if (count($out) >= $limit) {
				break;
			}
		}
		return $out;
	}
}
