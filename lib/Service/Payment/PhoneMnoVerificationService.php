<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Db\Payment;
use OCA\Libresign\Db\PhoneMnoCacheMapper;
use OCA\Libresign\Enum\PaymentMethod;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Enum\ResolutionConfidence;
use OCA\Libresign\Service\Payment\DTO\PaymentMetadataDTO;
use Psr\Log\LoggerInterface;

class PhoneMnoVerificationService {
	public function __construct(
		private PhoneMnoCacheMapper $cacheMapper,
		private MnoSuggestionValidatorService $mnoSuggestionValidatorService,
		private MnoRoutingRegistry $mnoRoutingRegistry,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Remember the MNO/provider combination actually used by a
	 * successfully completed mobile-money payment.
	 *
	 * This is deliberately best-effort. Failure to update the phone/MNO
	 * cache must never affect the payment or entitlement lifecycle.
	 */
	public function verify(Payment $payment): void {
		if ($payment->getPaymentStatus() !== PaymentStatus::PAID) {
			return;
		}

		$metadata = $payment->getProviderMetadataObject();

		if (!$metadata instanceof PaymentMetadataDTO) {
			$this->logger->warning('[PhoneMnoVerification] Missing payment metadata', [
				'paymentId' => $payment->getId(),
			]);
			return;
		}

		$paymentMethod = $metadata->method ?? null;

		if (PaymentMethod::tryFrom($paymentMethod) !== PaymentMethod::MOBILE) {
			return;
		}

		$phone = $payment->getPhoneE164Digits();
		$region = $payment->getPhoneRegion();
		$country = $payment->getPhoneCountry();

		if ($phone === null || $phone === '') {
			$this->logger->debug('[PhoneMnoVerification] Missing phone number', [
				'paymentId' => $payment->getId(),
			]);
			return;
		}

		$provider = $this->resolveProvider($metadata);

		if ($provider === null) {
			$this->logger->warning('[PhoneMnoVerification] Unable to determine executed provider', [
				'paymentId' => $payment->getId(),
			]);
			return;
		}

		$mno = $this->resolveMno($provider, $metadata, $region);

		if ($mno === null || $mno === '') {
			$this->logger->warning('[PhoneMnoVerification] Unable to determine verified MNO', [
				'paymentId' => $payment->getId(),
				'provider' => $provider->value,
			]);
			return;
		}

		$providerMnoKey = $this->resolveProviderMnoKey(
			$provider,
			$metadata,
		);

		$carrier = $metadata->context['carrier'] ?? null;

		if (!is_string($carrier) || trim($carrier) === '') {
			$carrier = null;
		} else {
			$carrier = trim($carrier);
		}

		try {
			$this->cacheMapper->store(
				phoneE164Digits: $phone,
				region: $region,
				country: $country,
				mno: $mno,
			    carrierHint: $carrier,
				confidence: ResolutionConfidence::HIGH->value,
				resolverVersion:PhoneMnoResolver::RESOLVER_VERSION,
				now: new \DateTimeImmutable(),
				provider: $provider->value,
				providerMnoKey: $providerMnoKey,
				verified: true,
			);
		} catch (\Throwable $e) {
			$this->logger->warning('[PhoneMnoVerification] Failed to persist verified MNO', [
				'paymentId' => $payment->getId(),
				'phone' => $phone,
				'mno' => $mno,
				'provider' => $provider->value,
				'error' => $e->getMessage(),
			]);
		}
	}

	private function resolveProvider(
		PaymentMetadataDTO $metadata,
	): ?PaymentProvider {
		$provider = $metadata->executedProvider;

		if ($provider !== null && $provider !== '') {
			return PaymentProvider::tryFrom($provider);
		}

		return null;
	}

	private function resolveMno(
		PaymentProvider $provider,
		PaymentMetadataDTO $metadata,
		?string $region,
	): ?string {
		if ($provider === PaymentProvider::DARAJA) {
			$carrier = $metadata->context['carrier'] ?? null;

			if (!is_string($carrier) || trim($carrier) === '') {
				return null;
			}

			return $this->mnoRoutingRegistry->mnoKeyForProvider(
				$region,
				$carrier,
				$provider,
			);
		}

		if ($provider === PaymentProvider::DPO) {
			$providerMnoKey = $metadata->selected->mno ?? null;

			if (!is_string($providerMnoKey) || trim($providerMnoKey) === '') {
				return null;
			}

			return $this->mnoSuggestionValidatorService
				->canonicalMnoForOption($providerMnoKey, $region);
		}

		return null;
	}

	private function resolveProviderMnoKey(
		PaymentProvider $provider,
		PaymentMetadataDTO $metadata,
	): ?string {
		if ($provider !== PaymentProvider::DPO) {
			return null;
		}

		$mno = $metadata->selected->mno ?? null;

		if (!is_string($mno) || trim($mno) === '') {
			return null;
		}

		return strtolower(trim($mno));
	}
}
