<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Db\Payment;
use OCA\Libresign\Db\PaymentMapper;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Enum\ProviderExecutionState;
use OCA\Libresign\Service\DateTimeService;
use OCA\Libresign\Service\Entitlement\EntitlementService;
use OCA\Libresign\Service\Payment\DTO\PaymentMetadataDTO;
use OCA\Libresign\Service\Payment\VerificationService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Owns payment STATE TRANSITIONS — the verbs that write a payment's
 * financial status.
 *
 * OWNS:
 * - Every setPaymentStatus write in the system. If a payment's status
 *   changes, a verb here did it. This single-ownership is the guarantee
 *   that status (the entitlement-granting financial state) has one place
 *   it can be mutated.
 * - The finalise transaction (status → PAID + entitlement grant, atomic).
 * - Best-effort provider-side token cancellation.
 *
 * DOES NOT OWN:
 * - Deciding WHEN to transition (predicates like hasPaymentExpired,
 *   stale-for-retry live with the coordinator/validation layer).
 * - Provider reconciliation / interpreting provider results — that is
 *   PaymentReconciliationService, which calls DOWN into these verbs.
 * - Metadata ENRICHMENT. Callers (reconciliation) build rich provider
 *   payloads and pass them in via $enrichedMeta; these verbs persist
 *   what they are handed alongside the status write. Verbs do not grow
 *   per provider, the payload richness is the caller's concern, the
 *   status write resides in this Service.
 */
class PaymentLifecycleService
{
	public function __construct(
		private PaymentMapper $paymentMapper,
		private IDBConnection $db,
		private EntitlementService $entitlementService,
		private VerificationService $verificationService,
		private DateTimeService $dateTimeService,
		private PaymentMetadataHelperService $metadataHelper,
		private LoggerInterface $logger,
	) {}

	/**
	 * Finalise a successful payment: status → PAID and grant entitlement,
	 * atomically. Idempotent — a duplicate provider callback on an already
	 * PAID row is a no-op.
	 *
	 * The financial state update and the access grant MUST NOT partially
	 * succeed, hence the single transaction with full rollback.
	 *
	 * @throws \Throwable
	 */
	public function finalisePayment(Payment $payment): void
	{
		/**
		 * IDEMPOTENCY GUARD
		 *
		 * Payment providers may send duplicate callbacks.
		 * If already processed, exit early.
		 */
		if ($payment->getPaymentStatus() === PaymentStatus::PAID) {
			return;
		}

		/**
		 * CRITICAL TRANSACTION
		 *
		 * Ensures atomic consistency between:
		 * - Payment status update (financial state)
		 * - Entitlement creation (access state)
		 *
		 * MUST NOT partially succeed.
		 */
		$this->db->beginTransaction();

		try {
			$this->logger->info('[PAYMENT FINALISE] Before update', [
				'id' => $payment->getId(),
				'status' => $payment->getPaymentStatus()->value,
			]);

			$now = $this->dateTimeService->now();

			$payment->setUpdatedAt($now);
			$payment->setPaymentStatus(PaymentStatus::PAID);
			$payment->setPaidAt($now);

			$this->paymentMapper->update($payment);

			/**
			 * BUSINESS FINALITY
			 *
			 * IMPORTANT:
			 * Different payment purposes may trigger
			 * different business workflows.
			 */
			switch ($payment->getPaymentPurpose()) {
				case PaymentPurpose::CREDIT_PURCHASE:
					// Future:
					// - invoicing
					// - bulk credit notifications
					break;
				case PaymentPurpose::SIGN_REQUEST:
					// Future:
					// - signer-specific workflows
					// - document-level reconciliation
					break;
			}

			/**
			 * Grant entitlement from successful payment.
			 *
			 * IMPORTANT:
			 * Current business model grants entitlement
			 * for both:
			 * - direct signer payments
			 * - requester credit purchases
			 */

			$this->entitlementService->create(
				$payment->getUserId(),
				$payment->getProductCode(),
				$payment->getProductUses()
			);

			$this->db->commit();

			$this->logger->info('[PAYMENT FINALISE] After update call', [
				'id' => $payment->getId(),
				'status' => $payment->getPaymentStatus()->value,
				'purpose' => $payment->getPaymentPurpose()->value,
			]);
		} catch (\Throwable $e) {

			/**
			 * Rollback ALL changes to prevent partial state
			 */
			$this->db->rollBack();

			$this->logger->error('[PAYMENT FINALISE] failed', [
				'exception' => $e,
				'paymentId' => $payment->getId(),
				'purpose' => $payment->getPaymentPurpose()->value,
			]);

			throw $e;
		}
	}

	/**
	 * Transition a payment to FAILED with a reason.
	 *
	 * Optionally persists a caller-enriched metadata DTO (e.g. a provider
	 * query payload from reconciliation) alongside the status write, so the
	 * rich audit trail and the status change land in a single update. When
	 * no enriched meta is given, the payment's current metadata is used.
	 */
	public function markFailed(
		Payment $payment,
		string $reason,
		?PaymentMetadataDTO $enrichedMeta = null,
	): void {
		$nowImmutable = $this->dateTimeService->nowImmutable();

		$now = $this->dateTimeService->toAtom($nowImmutable);

		$payment->setUpdatedAt($now);
		$payment->setPaymentStatus(PaymentStatus::FAILED);

		$meta = $enrichedMeta ?? $payment->getProviderMetadataObject();

		// Merge canonical failure fields onto any caller-staged audit
		// (e.g. reconciliation's source/raw-result) rather than replacing,
		// so both the audit and the canonical summary survive.
		$meta = $this->metadataHelper->appendProviderError(
			$meta,
			[
				'type' => 'failed',
				'reason' => $reason,
				'timestamp' => $now,
			],
			$nowImmutable
		);

		$payment->setProviderMetadataObject($meta);
		$this->paymentMapper->update($payment);
	}

	/**
	 * Transition a payment to CANCELLED with a reason.
	 *
	 * Same enriched-metadata contract as markFailed — the caller may hand
	 * in a provider payload to persist with the status change.
	 */
	public function markCancelled(
		Payment $payment,
		string $reason,
		?PaymentMetadataDTO $enrichedMeta = null,
	): void {
		$nowImmutable = $this->dateTimeService->nowImmutable();

		$now = $this->dateTimeService->toAtom($nowImmutable);

		$payment->setUpdatedAt($now);
		$payment->setPaymentStatus(PaymentStatus::CANCELLED);

		$meta = $enrichedMeta ?? $payment->getProviderMetadataObject();

		// Merge canonical failure fields onto any caller-staged audit
		// (e.g. reconciliation's source/raw-result) rather than replacing,
		// so both the audit and the canonical summary survive.
		$meta = $this->metadataHelper->appendProviderError(
			$meta,
			[
				'type' => 'cancelled',
				'reason' => $reason,
				'timestamp' => $this->dateTimeService->now(),
			],
			$nowImmutable,
		);

		$payment->setProviderMetadataObject($meta);
		$this->paymentMapper->update($payment);
	}

	/**
	 * Transition a payment to EXPIRED with a reason (default 'expired').
	 *
	 * Distinct from expirePayment(): background reconciliation marks a payment
	 * EXPIRED — its own terminal status — rather than FAILED, so the FE and
	 * reporting can tell an abandoned poll-loop session apart from a genuine
	 * failure. expirePayment() stays FAILED for UX-flow expiry. Same
	 * enriched-metadata contract as markFailed/markCancelled.
	 */
	public function markExpired(
		Payment $payment,
		string $reason = 'expired',
		?PaymentMetadataDTO $enrichedMeta = null,
	): void {
		$nowImmutable = $this->dateTimeService->nowImmutable();
		$now = $this->dateTimeService->toAtom($nowImmutable);

		$payment->setUpdatedAt($now);
		$payment->setPaymentStatus(PaymentStatus::EXPIRED);

		$meta = $enrichedMeta ?? $payment->getProviderMetadataObject();

		$meta = $this->metadataHelper->appendProviderError(
			$meta,
			[
				'type' => 'expired',
				'reason' => $reason,
				'timestamp' => $now,
			],
			$nowImmutable,
		);

		$payment->setProviderMetadataObject($meta);
		$this->paymentMapper->update($payment);
	}

	/**
	 * Expire a payment (status → FAILED, reason defaults to 'expired').
	 *
	 * Currently a thin delegate to markFailed. Kept as a distinct verb
	 * because expiry is a conceptually separate event that may diverge from
	 * generic failure later (e.g. its own status or downstream handling).
	 */
	public function expirePayment(Payment $payment, string $reason = 'expired'): void
	{
		$this->markFailed($payment, $reason);
	}

	/**
	 * Mark a payment attempt as failed during provider initiation.
	 *
	 * No provider session was established, so no reconciliation or polling
	 * should continue; the FE may safely reset and allow a fresh attempt.
	 * Used when the provider throws during initiation or returns an invalid
	 * initiation response.
	 */
	public function failPaymentInitiation(
		Payment $payment,
		string $message,
		?PaymentProvider $provider = null,
		?\Throwable $exception = null,
	): void {
		$nowImmutable = $this->dateTimeService->nowImmutable();
		$now = $this->dateTimeService->toAtom($nowImmutable);
		$status = PaymentStatus::INITIATION_FAILED;

		$payment->setUpdatedAt($now);
		$payment->setPaymentStatus($status);

		$meta = $payment->getProviderMetadataObject();

		$error = [
			'type' => $status->value,
			'message' => $message,
			'provider' => $provider?->value ?? 'unknown',
			'timestamp' => $now,
		];

		if ($meta !== null) {
			$payment->setProviderMetadataObject(
				$this->metadataHelper->appendProviderError(
					$meta,
					$error,
					$nowImmutable,
				)->with(
					providerExecutionState: ProviderExecutionState::FAILED,
				)
			);
		}

		$this->paymentMapper->update($payment);

		$this->logger->warning('[Payment] Provider initiation failed', [
			'paymentId' => $payment->getId(),
			'userId' => $payment->getUserId(),
			'provider' => $provider?->value,
			'message' => $message,
			'status' => $status->value,
			'exception' => $exception?->getMessage(),
		]);
	}

	/**
	 * Best-effort provider-side cancellation of a still-chargeable token.
	 *
	 * DPO tokens can be cancelled so the reference can't be charged later;
	 * Daraja has no cancellation API (its STK ref expires provider-side).
	 * Failure is tolerated — marking the row terminal is what protects us,
	 * not the provider ack.
	 */
	public function cancelProviderToken(Payment $payment): void
	{
		if ($payment->getProviderEnum() !== PaymentProvider::DPO) {
			return;
		}

		$reference = $payment->getProviderReference();

		try {
			$result = $this->verificationService->cancelPayment(
				$payment->getProviderEnum(),
				$reference
			);

			if (($result['status'] ?? null) !== 'SUCCESS') {
				$this->logger->warning('[Payment] Provider cancellation unsuccessful', [
					'reference' => $reference,
					'code' => $result['code'] ?? null,
					'explanation' => $result['explanation'] ?? null,
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('[Payment] Provider cancellation failed (non-blocking)', [
				'reference' => $reference,
				'provider' => $payment->getProviderEnum()->value,
				'error' => $e->getMessage(),
			]);
		}
	}
}
