<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Db\Payment;
use OCA\Libresign\Enum\PaymentFlow;
use OCA\Libresign\Enum\PaymentMethod;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Enum\ProviderExecutionState;
use OCA\Libresign\Service\Payment\DTO\ExistingPaymentResultDTO;
use OCA\Libresign\Service\Payment\DTO\StartPaymentResultDTO;

/**
 * Maps Payment entities to API-facing DTOs.
 *
 * Keeps response contract construction in one place so the
 * orchestration services do not need to know frontend details.
 */
class PaymentResponseFactory {
	public function __construct(
		private AmountResolver $amountResolver,
	) {
	}

	public function buildExistingPaymentResponse(Payment $payment): ExistingPaymentResultDTO {
		$meta = $payment->getProviderMetadataObject();
		$status = $payment->getPaymentStatus();

		[$displayAmount, $displayAmountFormatted] = $this->resolveDisplayAmount(
			$payment->getDisplayAmount(),
			$payment->getDisplayCurrency(),
		);

		return new ExistingPaymentResultDTO(
			updatedAt: $payment->getUpdatedAtImmutable(),
			paymentId: $payment->getId(),
			signRequestId: $payment->getTransactionId(),
			signUuid: $payment->getTransactionReference(),
			reference: $payment->getProviderReference(),
			status: $this->mapPaymentStatus($status),
			provider: $payment->getPaymentProvider(),
			flow: $meta?->flow
				? PaymentFlow::tryFrom($meta->flow) ?? PaymentFlow::UNKNOWN
				: PaymentFlow::UNKNOWN,
			method: $meta?->method
				? PaymentMethod::tryFrom($meta->method) ?? null
				: null,
			redirectUrl: $meta?->redirectUrl,
			instructions: $meta?->instructions,
			mno: $meta?->suggested?->mno,
			country: $meta?->suggested?->country,
			alreadyCharged: $meta?->alreadyCharged ?? false,
			providerExecutionState: $meta?->providerExecutionState ?? ProviderExecutionState::UNKNOWN,
			selected: $meta?->selected,
			confidence: $meta?->confidence,
			requiresProviderSelection: $meta?->selection?->required ?? false,
			options: $meta?->selection?->options ?? [],
			phoneNumber: $payment->getPhoneE164Digits(),
			phoneNumberRegion: $payment->getPhoneRegion(),
			phoneNumberCountry: $payment->getPhoneCountry(),
			displayAmount: $displayAmount,
			displayAmountFormatted: $displayAmountFormatted,
			displayCurrency: $payment->getDisplayCurrency(),
			paymentPurpose: $payment->getPaymentPurpose(),
			quantity: $payment->getQuantity(),
			unitAmount: $payment->getUnitAmount(),
		);
	}

	public function buildStartPaymentResult(
		Payment $payment,
		PaymentMethod $method,
	): StartPaymentResultDTO {
		$meta = $payment->getProviderMetadataObject();

		[$displayAmount, $displayAmountFormatted] = $this->resolveDisplayAmount(
			$payment->getDisplayAmount(),
			$payment->getDisplayCurrency(),
		);

		return new StartPaymentResultDTO(
			updatedAt: $payment->getUpdatedAtImmutable(),
			paymentId: $payment->getId(),
			signRequestId: $payment->getTransactionId(),
			signUuid: $payment->getTransactionReference(),
			reference: $payment->getProviderReference() ?? '',
			provider: $payment->getPaymentProvider(),
			flow: $meta?->flow
				? PaymentFlow::tryFrom($meta->flow) ?? PaymentFlow::UNKNOWN
				: PaymentFlow::UNKNOWN,
			method: $method,
			redirectUrl: $meta?->redirectUrl,
			mno: $meta?->suggested?->mno,
			country: $meta?->suggested?->country,
			alreadyCharged: $meta?->alreadyCharged ?? false,
			providerExecutionState: $meta?->providerExecutionState ?? ProviderExecutionState::UNKNOWN,
			selected: $meta?->selected,
			confidence: $meta?->confidence,
			requiresProviderSelection: $meta?->selection?->required ?? false,
			options: $meta?->selection?->options ?? [],
			phoneNumber: $payment->getPhoneE164Digits(),
			phoneNumberRegion: $payment->getPhoneRegion(),
			phoneNumberCountry: $payment->getPhoneCountry(),
			displayAmount: $displayAmount,
			displayAmountFormatted: $displayAmountFormatted,
			displayCurrency: $payment->getDisplayCurrency(),
			paymentPurpose: $payment->getPaymentPurpose(),
			quantity: $payment->getQuantity(),
			unitAmount: $payment->getUnitAmount(),
		);
	}

	public function mapPaymentStatus(PaymentStatus $status): string {
		return match ($status) {
			PaymentStatus::PAID => 'SUCCESS',
			PaymentStatus::INITIATION_FAILED => 'INITIATION_FAILED',
			PaymentStatus::EXPIRED => 'EXPIRED',
			PaymentStatus::CANCELLED => 'CANCELLED',
			PaymentStatus::FAILED => 'FAILED',
			default => 'PENDING',
		};
	}

	/**
	 * Resolve a safe, user-facing failure reason key for a payment.
	 *
	 * Raw provider descriptions and internal error details are NOT returned;
	 * only a curated machine-readable key that the frontend can map to a
	 * localised message.
	 */
	public function getPaymentFailureReason(Payment $payment): ?string {
		$status = $payment->getPaymentStatus();

		if (!in_array($status, [PaymentStatus::FAILED, PaymentStatus::CANCELLED, PaymentStatus::EXPIRED], true)) {
			return null;
		}

		$meta = $payment->getProviderMetadataObject();
		$reason = ($meta->providerError ?? [])['reason'] ?? null;

		if (is_string($reason) && $reason !== '') {
			return $reason;
		}

		return match ($status) {
			PaymentStatus::CANCELLED => 'cancelled',
			PaymentStatus::EXPIRED => 'expired',
			PaymentStatus::FAILED => 'generic_failure',
			default => null,
		};
	}

	/**
	 * @return array{0: ?float, 1: ?string}
	 */
	private function resolveDisplayAmount(
		?int $displayAmountMinor,
		?string $displayCurrency,
	): array {
		if ($displayAmountMinor === null || $displayCurrency === null) {
			return [null, null];
		}

		return [
			$this->amountResolver->toMajorUnits($displayAmountMinor, $displayCurrency),
			$this->amountResolver->format($displayAmountMinor, $displayCurrency),
		];
	}
}
