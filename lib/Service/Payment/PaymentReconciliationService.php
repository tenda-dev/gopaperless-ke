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
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Service\DateTimeService;
use OCA\Libresign\Service\Payment\DTO\PaymentMetadataDTO;
use OCA\Libresign\Service\Payment\VerificationService;
use Psr\Log\LoggerInterface;

/**
 * Owns PROVIDER RECONCILIATION — reading a provider's truth and resolving
 * our payment to match it.
 *
 * OWNS:
 * - verifyPayment / queryPayment: ask the provider "what happened?" and
 *   drive the payment to the matching terminal state.
 * - reconcileThenExpire: the shared "reconcile first, then end the session
 *   unless it actually paid" head used by invalidate / stale-retry / retry.
 * - Building rich, per-provider metadata (query payloads, audit trails).
 *
 * DOES NOT OWN:
 * - Status writes. Every setPaymentStatus goes DOWN into a Lifecycle verb
 *   (finalisePayment / markFailed / markCancelled / expirePayment). This
 *   service constructs the enriched metadata and hands it to Lifecycle to
 *   persist alongside the status change — it never calls setPaymentStatus
 *   or the mapper's update() for a status transition itself.
 * - Deciding when to reconcile (the coordinator triggers these).
 *
 * The one exception to "no writes": provider fetch is via the mapper, and
 * where a method needs the payment it reads through the mapper. It performs
 * no status persistence of its own.
 */
class PaymentReconciliationService
{
	public function __construct(
		private PaymentMapper $paymentMapper,
		private VerificationService $verificationService,
		private PaymentLifecycleService $lifecycle,
		private DateTimeService $dateTimeService,
		private PaymentMetadataHelperService $metadataHelper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Light post-redirect verification (UX boost). One-shot, no retries,
	 * no scheduling, no locking. DPO only — Daraja resolves via callback.
	 */
	public function verifyPayment(string $reference): PaymentStatus
	{
		$payment = $this->paymentMapper->findByProviderReferenceOrFail($reference);

		// Already resolved → return immediately
		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return $payment->getPaymentStatus();
		}

		if ($payment->getProviderEnum() !== PaymentProvider::DPO) {
			return $payment->getPaymentStatus();
		}

		try {

			// One-shot verification (UX boost)
			$status = $this->verificationService->verifyStatus(
				$payment->getProviderEnum(),
				$reference
			);

			$payment->setVerificationStatus($status);
			$payment->setVerificationLastCheckedAt($this->dateTimeService->now());

			if ($status === 'SUCCESS') {

				$this->lifecycle->finalisePayment($payment);
				return PaymentStatus::PAID;
			}

			if ($status === 'FAILED') {

				$this->lifecycle->markFailed($payment, 'generic_failure');
				return PaymentStatus::FAILED;
			}

			// Still pending → background job will resolve it.
			$this->paymentMapper->update($payment);
			return PaymentStatus::PENDING;
		} catch (\Throwable $e) {

			// Non-blocking: never fail hard, the background job retries.
			$this->logger->warning('[Payment] verifyPayment failed (non-blocking)', [
				'reference' => $reference,
				'error' => $e->getMessage(),
			]);

			return PaymentStatus::PENDING;
		}
	}

	/**
	 * Daraja STK status query — fallback when the callback is delayed or
	 * missing, triggered by the client after a wait. Daraja only.
	 *
	 * Builds the provider query payload (its own concern) and delegates
	 * every status write to Lifecycle.
	 */
	public function queryPayment(string $reference): PaymentStatus
	{
		$payment = $this->paymentMapper->findByProviderReferenceOrFail($reference);

		if ($payment->getProviderEnum() !== PaymentProvider::DARAJA) {
			return $payment->getPaymentStatus();
		}

		// Already resolved by callback → do not re-query or override.
		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return $payment->getPaymentStatus();
		}

		try {
			$result = $this->verificationService->query(
				$payment->getProviderEnum(),
				$reference
			);

			/**
			 * Store query response for debugging / audit
			 */
			$meta = $payment->getProviderMetadataObject();

			$nowImmutable = $this->dateTimeService->nowImmutable();

			$payment->setVerificationStatus($result['status'] ?? 'PENDING');
			$payment->setVerificationLastCheckedAt(
				$this->dateTimeService->toAtom($nowImmutable)
			);

			switch ($result['status']) {

				case 'SUCCESS':
					// SUCCESS: set enriched meta on the payment, then finalise
					// (finalisePayment reads meta off the payment; it takes
					// no enriched-meta argument).
					// metadata set here persists via finalisePayment's update() of this same object
					$meta = $meta->with(
						updatedAt: $nowImmutable,
						providerPayload: $this->metadataHelper
							->getProviderPayload($meta)
							->withQuery($result),
					);
					$payment->setProviderMetadataObject($meta);

					$this->lifecycle->finalisePayment($payment);
					return PaymentStatus::PAID;

				case 'FAILED':
					$enriched = $this->buildQueryAuditMeta(
						meta: $meta,
						result: $result,
					);
					$this->lifecycle->markFailed(
						$payment,
						$result['reason'] ?? 'generic_failure',
						$enriched,
					);
					return PaymentStatus::FAILED;

				case 'CANCELLED':
					$enriched = $this->buildQueryAuditMeta(
						meta: $meta,
						result: $result,
					);
					$this->lifecycle->markCancelled(
						$payment,
						$result['reason'] ?? 'cancelled',
						$enriched,
					);
					return PaymentStatus::CANCELLED;

				case 'PENDING':
				default:
					$this->paymentMapper->update($payment);
					return PaymentStatus::PENDING;
			}
		} catch (\Throwable $e) {
			// Query is a fallback — never break the flow or mark failed here;
			// let the callback still resolve the payment.
			$this->logger->error('Daraja query failed', [
				'reference' => $reference,
				'error' => $e->getMessage(),
			]);

			return PaymentStatus::PENDING;
		}
	}

	/**
	 * Reconcile a still-pending payment with its provider, then expire it
	 * unless the provider reports the money already moved.
	 *
	 * The shared head behind every "force this session to end" path
	 * (user invalidation, stale-retry, explicit retry): always ask the
	 * provider first, never kill a session that actually took payment.
	 *
	 * @param string $failureReason applied only when a still-pending
	 *                              session is expired (step 6).
	 */
	public function reconcileThenExpire(
		Payment $payment,
		string $failureReason,
	): PaymentStatus {

		$reference = $payment->getProviderReference();

		// 1 — idempotency + delayed-PAID guard
		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			$this->logger->info('[Payment] reconcileThenExpire no-op (already terminal)', [
				'reference' => $reference,
				'status' => $payment->getPaymentStatus()->value,
			]);
			return $payment->getPaymentStatus();
		}

		// 2 — provider-appropriate reconcile (both finalise-on-success)
		match ($payment->getProviderEnum()) {
			PaymentProvider::DPO => $this->verifyPayment($reference),
			PaymentProvider::DARAJA => $this->queryPayment($reference),
			default => null,
		};

		// 3 — re-fetch; reconcile may have finalised/failed the row
		$payment = $this->paymentMapper->findByProviderReferenceOrFail($reference);

		// 4 — money moved, never expire
		if ($payment->getPaymentStatus() === PaymentStatus::PAID) {
			$this->logger->info('[Payment] reconcileThenExpire aborted — payment completed', [
				'reference' => $reference,
			]);
			return PaymentStatus::PAID;
		}

		// 5 — provider resolved it terminal (non-PAID); respect its reason
		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			$this->logger->info('[Payment] reconcileThenExpire — provider resolved terminal', [
				'reference' => $reference,
				'status' => $payment->getPaymentStatus()->value,
			]);
			return $payment->getPaymentStatus();
		}

		// 6 — still pending: cancel token, then expire
		$this->lifecycle->cancelProviderToken($payment);
		$this->lifecycle->expirePayment($payment, $failureReason);

		$this->logger->info('[Payment] reconcileThenExpire — expired still-pending session', [
			'reference' => $reference,
			'reason' => $failureReason,
			'payment_id' => $payment->getId(),
		]);

		return PaymentStatus::FAILED;
	}

	/**
	 * Build the enriched metadata for a FAILED/CANCELLED Daraja query:
	 * store the raw query result for audit and attach a source-tagged
	 * providerError. Status is NOT written here; Lifecycle does that when
	 * this DTO is handed to markFailed / markCancelled.
	 */
	private function buildQueryAuditMeta(
		PaymentMetadataDTO $meta,
		array $result,
	): PaymentMetadataDTO {
		return $meta->with(
			providerPayload: $this->metadataHelper
				->getProviderPayload($meta)
				->withQuery($result),
		);
	}
}
