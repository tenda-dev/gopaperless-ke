<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Db\Payment;
use OCA\Libresign\Db\PaymentMapper;
use OCA\Libresign\Enum\PaymentErrorCode;
use OCA\Libresign\Enum\PaymentMethod;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Service\DateTimeService;
use OCA\Libresign\Service\Payment\DTO\GuardOutcome;
use OCA\Libresign\Service\Payment\DTO\PaymentRouteContextDTO;
use OCA\Libresign\Service\Payment\DTO\StartPaymentDTO;
use OCA\Libresign\Service\Payment\Exceptions\PaymentValidationException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Owns the dedup / idempotency GUARDS for a new payment attempt, plus the
 * operational-window predicates when the rest of the system asks about a payment.
 *
 * The native thrower of PaymentValidationException for the start path: it
 * throws on absent/invalid fields, and otherwise returns a three-way
 * GuardOutcome — proceed (with resolved attempt id) or return an existing
 * payment. It reconciles-before-recycling (never recycles a stale route,
 * never discards a delayed success) by delegating provider truth to
 * PaymentReconciliationService and status writes down to PaymentLifecycleService; it
 * should never call setPaymentStatus itself.
 */
class PaymentValidationService
{
	/**
	 * Operational payment expiry window. Mobile/card checkouts are short-lived
	 * UX flows; expiry defines reconciliation finality and stops background
	 * verification. Typical completion 30s–5m; >10m usually abandoned.
	 */
	private const PAYMENT_EXPIRY_SECONDS = 12 * 60;

	/**
	 * How long an already-charged async payment may stay PENDING before a
	 * user retry starts a fresh attempt. Long enough for a normal interaction,
	 * short enough that a stuck STK/push session doesn't block retries.
	 */
	private const PAYMENT_STALE_FOR_RETRY_SECONDS = 90;

	public function __construct(
		private PaymentMapper $paymentMapper,
		private PaymentLifecycleService $lifecycle,
		private PaymentReconciliationService $reconciliation,
		private DateTimeService $dateTimeService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Dedup / idempotency gate for startPayment. Three outcomes:
	 * - throw  → a required field is absent (native PaymentValidationException)
	 * - return existing → a paid, reusable-pending, or reconciled-to-paid row
	 *   the caller should surface as-is
	 * - proceed(attemptId) → no blocking row; continue with the resolved id
	 *
	 * @throws PaymentValidationException
	 */
	public function guard(
		StartPaymentDTO $dto,
		PaymentRouteContextDTO $ctx
		): GuardOutcome
	{
		// Universal required fields (guard needs both for the credit path).
		if (!$dto->userId) {
			throw new PaymentValidationException(
				PaymentErrorCode::MISSING_FIELD,
				'userId is required',
				retryable: false
			);
		}

		if (!$dto->productCode) {
			throw new PaymentValidationException(
				PaymentErrorCode::MISSING_FIELD,
				'productCode is required',
				retryable: false
			);
		}

		$purpose = PaymentPurpose::tryFrom($dto->purpose->value) ?? PaymentPurpose::SIGN_REQUEST;
		$route = $ctx->route;
		$methodEnum = $dto->paymentMethod;
		$e164 = $ctx->e164;

		$pending = null;

		if ($purpose === PaymentPurpose::SIGN_REQUEST) {

			if ($dto->signRequestId === null) {
				throw new PaymentValidationException(
					PaymentErrorCode::MISSING_FIELD,
					'signRequestId is required',
					retryable: false
				);
			}

			if ($dto->signUuid === null || $dto->signUuid === '') {
				throw new PaymentValidationException(
					PaymentErrorCode::MISSING_FIELD,
					'signUuid is required',
					retryable: false
				);
			}

			$paidPayment = $this->paymentMapper->findLatestPaidByTransactionId($dto->signRequestId);

			if ($paidPayment) {
				$this->logger->info('[Payment] Returning existing paid payment instead of starting a new one', [
					'sign_request_id' => $dto->signRequestId,
					'payment_id' => $paidPayment->getId(),
				]);
				return GuardOutcome::returnExisting($paidPayment);
			}

			$pending = $this->paymentMapper
				->findLatestPendingByTransactionId($dto->signRequestId);
		} else {

			$pending = $this->paymentMapper->findLatestPendingCreditPurchaseByUserId(
				$dto->userId,
				$dto->productCode,
			);
		}

		if ($pending) {
			$pending = $this->paymentMapper->findById($pending->getId());

			$reusable = false;

			if (!$this->hasPaymentExpired($pending)) {

				$meta = $pending->getProviderMetadataObject();

				/**
				 * Routing may have changed since the pending payment
				 * was created (e.g. KE Safaricom fix switched from
				 * DPO to Daraja). Do not recycle a stale route.
				 */
				if (
					$route !== null
					&& $pending->getProviderEnum() !== $route->preferredProvider
				) {
					$this->logger->info('[Payment] Expiring pending payment because route provider changed', [
						'pending_provider' => $pending->getProviderEnum()->value,
						'route_provider' => $route->preferredProvider->value,
						'payment_id' => $pending->getId(),
					]);

					$this->lifecycle->expirePayment($pending);

				} elseif ($meta->method !== $methodEnum->value) {

					$this->lifecycle->expirePayment($pending);

				} elseif (
					$methodEnum === PaymentMethod::MOBILE
					&& $e164 !== null
					&& $pending->getPhoneE164Digits() !== null
					&& $pending->getPhoneE164Digits() !== $e164
				) {
					$this->lifecycle->expirePayment($pending);
				} elseif ($this->isPendingStaleForRetry($pending)) {

					$resolved = $this->reconciliation->reconcileThenExpire($pending, 'expired');

					if ($resolved === PaymentStatus::PAID) {
						$paid = $this->paymentMapper->findByProviderReferenceOrFail(
							$pending->getProviderReference()
						);
						return GuardOutcome::returnExisting($paid);
					}

					// Non-PAID: reconcileThenExpire already marked the row
					// terminal. $reusable stays false → fall through to fresh
					// init. No extra expire needed.
				} else {

					$reusable = true;

				}
			} else {
				// expired by time
				$this->lifecycle->expirePayment($pending);
			}

			if ($reusable) {
				return GuardOutcome::returnExisting($pending);
			}

			$this->logger->info('[Payment] Expired pending payment', [
				'purpose' => $purpose->value,
				'sign_request_id' => $dto->signRequestId,
				'user_id' => $dto->userId,
				'product_code' => $dto->productCode,
			]);
		}

		$attemptId = $dto->paymentAttemptId ?: Uuid::uuid4()->toString();

		$existing = $this->paymentMapper->findByAttemptId($attemptId);
		if ($existing) {
			$existing = $this->paymentMapper->findById($existing->getId());
			return GuardOutcome::returnExisting($existing);
		}

		return GuardOutcome::proceed($attemptId);
	}

	/**
	 * Determine whether a pending payment session
	 * is no longer operationally valid.
	 *
	 * IMPORTANT:
	 * - Expiry is a UX/runtime boundary,
	 *   NOT provider settlement truth
	 *
	 * - Expired payments may still later reconcile
	 *   via async provider callbacks
	 *
	 * - Expiry prevents:
	 *   - stale session hydration
	 *   - duplicate polling recovery
	 *   - indefinite pending reuse
	 *   - abandoned payment resurrection
	 *
	 * FE recovery only resumes payments that:
	 * - are still pending (NOT TERMINAL)
	 * - are not expired
	 */
	public function hasPaymentExpired(Payment $payment): bool
	{
		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return false;
		}

		$createdAt = $payment->getCreatedAtImmutable();
		if ($createdAt === null) {
			return true;
		}

		$now = $this->dateTimeService->nowImmutable();

		$diff = $now->getTimestamp() - $createdAt->getTimestamp();
		return $diff > self::PAYMENT_EXPIRY_SECONDS;
	}

	/**
	 * Whether an already-charged async pending session is old enough that a
	 * user retry should create a fresh provider session. Only applies once the
	 * provider has been asked to collect funds (alreadyCharged).
	 */
	public function isPendingStaleForRetry(Payment $payment): bool
	{
		if ($payment->getPaymentStatus() !== PaymentStatus::PENDING) {
			return false;
		}

		if (!$payment->getProviderMetadataObject()->alreadyCharged) {
			return false;
		}

		$createdAt = $payment->getCreatedAtImmutable();

		if ($createdAt === null) {
			return true;
		}

		$diff = $this->dateTimeService->nowImmutable()->getTimestamp() - $createdAt->getTimestamp();
		return $diff > self::PAYMENT_STALE_FOR_RETRY_SECONDS;
	}
}
