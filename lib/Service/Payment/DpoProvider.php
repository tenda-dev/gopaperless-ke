<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Enum\PaymentFlow;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\ProviderExecutionState;
use OCA\Libresign\Enum\ResolutionConfidence;
use OCA\Libresign\Service\Payment\DTO\CardPaymentPayloadDTO;
use OCA\Libresign\Service\Payment\DTO\CardPaymentResultDTO;
use OCA\Libresign\Service\Payment\DTO\MobileMoneyChargeDTO;
use OCA\Libresign\Service\Payment\DTO\MobileMoneyPayloadDTO;
use OCA\Libresign\Service\Payment\DTO\MobileMoneyResultDTO;
use OCA\Libresign\Service\Payment\Interfaces\ICardProvider;
use OCA\Libresign\Service\Payment\Interfaces\IMobileMoneyProvider;
use OCA\Libresign\Service\Payment\Interfaces\IVerifiableProvider;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class DpoProvider implements IMobileMoneyProvider, ICardProvider, IVerifiableProvider
{
	private DpoPaymentService $dpo;
	private LoggerInterface $logger;
	private IRequest $request;

	public function __construct(
		DpoPaymentService $dpo,
		LoggerInterface $logger,
		IRequest $request,
	) {
		$this->dpo = $dpo;
		$this->logger = $logger;
		$this->request = $request;
	}


	public function getName(): PaymentProvider
	{
		return PaymentProvider::DPO;
	}

	/**
	 * MOBILE MONEY INITIATE
	 *
	 * PURE ADAPTER
	 * - No detection
	 * - No fallback
	 * - No intelligence
	 */
	public function initiateMobileMoney(MobileMoneyPayloadDTO $payload): MobileMoneyResultDTO
	{
		try {
			$result = $this->dpo->createToken(
				$payload->email,
				$payload->amount,
				$payload->redirectUrl ?? '',
				$payload->currency,
				'mobile',
				'MO',
				$payload->country // already resolved upstream
			);

			if (!isset($result['reference'])) {
				throw new RuntimeException('DPO initiate missing reference');
			}

			return new MobileMoneyResultDTO(
				providerExecutionState: ProviderExecutionState::REQUIRES_SELECTION,
				providerReference: $result['reference'],
				flow: PaymentFlow::MOBILE_DIRECT,
				provider: $this->getName(),
				message: 'Awaiting user confirmation',
				meta: [
					// keep structure to avoid FE regressions
					'selection' => [
						'options' => null,
						'suggestedMno' => $payload->mno,
						'confidence' => ResolutionConfidence::UNKNOWN->value,
						'country' => $payload->country,
					],
					'redirectUrl' => null,
					'providerPayload' => [
						'initiation' => is_array($result['raw'] ?? null)
							? $result['raw']
							: [],
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->error('[DPO] initiate failed', [
				'error' => $e->getMessage()
			]);

			throw $e;
		}
	}

	/**
	 * MOBILE MONEY CHARGE
	 */
	public function charge(MobileMoneyChargeDTO $payload): MobileMoneyResultDTO
	{
		try {
			$response = $this->dpo->chargeTokenMobile(
				$payload->providerReference,
				$payload->phone,
				strtolower($payload->mno),
				strtolower($payload->country)
			);

			$providerExecutionState = ($response['status'] ?? 'FAILED') === 'ACCEPTED'
				? ProviderExecutionState::EXECUTING
				: ProviderExecutionState::FAILED;

			return new MobileMoneyResultDTO(
				providerExecutionState: $providerExecutionState,
				providerReference: $payload->providerReference,
				flow: PaymentFlow::MOBILE_DIRECT,
				provider: $this->getName(),
				message: $providerExecutionState === ProviderExecutionState::EXECUTING
					? 'Payment prompt sent'
					: ($response['error'] ?? 'Payment failed'),
				errorCode: $providerExecutionState === ProviderExecutionState::FAILED
					? ($response['code'] ?? 'CHARGE_FAILED')
					: null,
				meta: [
					'instructions' => $response['instructions'] ?? null,
					'providerPayload' => [
						'charge' => is_array($response['raw'] ?? null)
							? $response['raw']
							: [],
					],
				],
			);
		} catch (Throwable $e) {
			$this->logger->error('[DPO] charge failed', [
				'reference' => $payload->providerReference,
				'error' => $e->getMessage()
			]);

			return new MobileMoneyResultDTO(
				providerExecutionState: ProviderExecutionState::FAILED,
				providerReference: $payload->providerReference,
				flow: PaymentFlow::MOBILE_DIRECT,
				provider: $this->getName(),
				message: 'Payment failed',
				errorCode: 'CHARGE_FAILED'
			);
		}
	}

	/**
	 * CARD INITIATE
	 */
	public function initiateCard(CardPaymentPayloadDTO $payload): CardPaymentResultDTO
	{

		if (
			!$payload->redirectUrl ||
			!filter_var($payload->redirectUrl, FILTER_VALIDATE_URL)
		) {
			throw new RuntimeException(
				'Valid redirect URL required'
			);
		}

		$allowedHosts = $this->getAllowedRedirectHosts();
		$redirectHost = parse_url($payload->redirectUrl, PHP_URL_HOST);
		if (!$redirectHost || !in_array($redirectHost, $allowedHosts, true)) {
			throw new RuntimeException('Redirect URL host not allowed');
		}

		$result = $this->dpo->createToken(
			$payload->email,
			$payload->amount,
			$payload->redirectUrl,
			$payload->currency,
			'card',
			'CC',
			null
		);

		return new CardPaymentResultDTO(
			providerExecutionState: ProviderExecutionState::EXECUTING,
			providerReference: $result['reference'],
			redirectUrl: $result['paymentUrl'],
			provider: $this->getName(),
			flow: PaymentFlow::REDIRECT,
			message: 'Redirect user to payment page',
			meta: [
				'providerPayload' => [
					'initiation' => is_array($result['raw'] ?? null)
						? $result['raw']
						: [],
				],
			]
		);
	}

	/**
	 * Transitional:
	 * Used by VerificationService / MobileMoneyService
	 * Will be removed once fully abstracted into service layer
	 */
	public function verifyStatus(string $reference): string
	{
		try {

			$result = $this->dpo->verifyToken($reference, false);

			$this->logger->info('[DPO] verifyStatus result', [
				'reference' => $reference,
				'status' => $result->status,
				'resultCode' => $result->resultCode,
				'explanation' => $result->explanation,
			]);

			return $result->status;
		} catch (Throwable $e) {

			$this->logger->warning('[DPO] verifyStatus failed', [
				'reference' => $reference,
				'error' => $e->getMessage(),
			]);

			/**
			 * IMPORTANT:
			 * Transport/provider verification failures
			 * are NOT terminal payment failures.
			 *
			 * Returning PENDING allows:
			 * - background retries
			 * - webhook reconciliation
			 * - eventual consistency recovery
			 */
			return 'PENDING';
		}
	}

	public function getMobileOptions(string $reference): array
	{
		return $this->dpo->getMobilePaymentOptions($reference);
	}

	/**
	 * Returns the list of hosts that are allowed as redirect targets.
	 *
	 * Defaults to the current request host. Override via app config if
	 * additional staging/white-label hosts are required.
	 */
	private function getAllowedRedirectHosts(): array
	{
		$currentHost = $this->request->getServerHost();
		return $currentHost !== '' ? [$currentHost] : [];
	}

	/**
	 * TEMP DEBUG
	 */
	public function test(): array
	{
		return $this->dpo->testDpo();
	}

	public function query(string $reference): array
	{
		throw new RuntimeException($this->getName()->value . ' does not support query fallback');
	}
}
