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
use OCA\Libresign\Service\Payment\DTO\MobileMoneyChargeDTO;
use OCA\Libresign\Service\Payment\DTO\MobileMoneyPayloadDTO;
use OCA\Libresign\Service\Payment\DTO\MobileMoneyResultDTO;
use OCA\Libresign\Service\Payment\Interfaces\IMobileMoneyProvider;
use OCA\Libresign\Service\Payment\Interfaces\IVerifiableProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class DarajaProvider implements IMobileMoneyProvider, IVerifiableProvider
{
	private DarajaService $daraja;

	public function __construct(
		DarajaService $daraja,
		private LoggerInterface $logger,
		)
	{
		$this->daraja = $daraja;
	}

	public function getName(): PaymentProvider
	{
		return PaymentProvider::DARAJA;
	}

	/**
	 * INITIATE (EXECUTES STK IMMEDIATELY)
	 */
	public function initiateMobileMoney(MobileMoneyPayloadDTO $payload): MobileMoneyResultDTO
	{
		try {
			$response = $this->daraja->initiatePayment([
				'amount' => $payload->amount,
				'phone' => $payload->phone,
				'internalReference' => $payload->email,
				'callbackUrl' => $payload->callbackUrl,
			]);

			if (!isset($response['reference']) || !$response['reference']) {
				throw new RuntimeException('Daraja response missing reference');
			}

			return new MobileMoneyResultDTO(
				providerExecutionState: ProviderExecutionState::EXECUTING,
				provider: PaymentProvider::DARAJA,
				providerReference: $response['reference'],
				flow: PaymentFlow::CALLBACK,
				message: 'STK push sent',
				meta: [
					'providerPayload' => [
						'initiation' => is_array($response['raw'] ?? null)
							? $response['raw']
							: [],
					],
				]
			);
		} catch (Throwable $e) {
			throw new RuntimeException(
				'Daraja initiate failed: ' . $e->getMessage(),
				0,
				$e
			);
		}
	}

	/**
	 * Daraja does NOT support deferred charge
	 */
	public function charge(MobileMoneyChargeDTO $payload): MobileMoneyResultDTO
	{
		return new MobileMoneyResultDTO(
			providerExecutionState: ProviderExecutionState::CANCELLED,
			provider: PaymentProvider::DARAJA,
			providerReference: $payload->providerReference,
			flow: PaymentFlow::CALLBACK,
			message: 'Invalid operation: Daraja does not support charge()',
			errorCode: 'INVALID_OPERATION'
		);
	}

	/**
	 * @return array{
	 *     status: 'SUCCESS'|'FAILED',
	 *     explanation: string,
	 *     code: string,
	 *     raw: array|null
	 * }
	 */
	public function cancel(string $reference): array
	{
		$this->logger->info('[Daraja] cancel requested but not supported', [
			'reference' => $reference,
		]);

		return [
			'status' => 'FAILED',
			'code' => 'UNSUPPORTED_OPERATION',
			'explanation' => 'Daraja does not support payment cancellation.',
			'raw' => null,
		];
	}

	/**
	 * KEEP FOR PaymentService
	 *
	 * Daraja is async → always pending here
	 */
	public function verifyStatus(string $reference): string
	{
		// async → DB is source of truth
		return 'PENDING';
	}

	/**
	 * Fallback polling
	 */
	public function query(string $reference): array
	{
		try {
			return $this->daraja->queryStkStatus($reference);
		} catch (Throwable $e) {
			throw $e;
		}
	}

	/**
	 * TEMP DEBUG
	 */
	public function test(): array
	{
		return $this->daraja->test();
	}
}
