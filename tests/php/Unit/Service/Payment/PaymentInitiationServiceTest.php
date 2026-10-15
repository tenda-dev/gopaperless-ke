<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Payment;

use OCA\Libresign\Db\Payment;
use OCA\Libresign\Enum\PaymentCapability;
use OCA\Libresign\Enum\PaymentFlowMode;
use OCA\Libresign\Enum\PaymentMethod;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Enum\ResolutionConfidence;
use OCA\Libresign\Service\Payment\CardService;
use OCA\Libresign\Service\Payment\DTO\AutoChargeDTO;
use OCA\Libresign\Service\Payment\DTO\ExistingPaymentResultDTO;
use OCA\Libresign\Service\Payment\DTO\MnoRoutingResultDTO;
use OCA\Libresign\Service\Payment\DTO\PaymentMetadataDTO;
use OCA\Libresign\Service\Payment\DTO\ProviderPayloadDTO;
use OCA\Libresign\Service\Payment\DTO\SelectedMnoDTO;
use OCA\Libresign\Service\Payment\DTO\SelectionDTO;
use OCA\Libresign\Service\Payment\DTO\StartPaymentDTO;
use OCA\Libresign\Service\Payment\DTO\SuggestedMnoDTO;
use OCA\Libresign\Service\Payment\FxEngineService;
use OCA\Libresign\Service\Payment\MobileMoneyService;
use OCA\Libresign\Service\Payment\PaymentDateTimeHelper;
use OCA\Libresign\Service\Payment\PaymentFactory;
use OCA\Libresign\Service\Payment\PaymentInitiationService;
use OCA\Libresign\Service\Payment\PaymentLifecycleService;
use OCA\Libresign\Service\Payment\PaymentRepository;
use OCA\Libresign\Service\Payment\PaymentResponseFactory;
use OCA\Libresign\Service\Payment\PaymentRoutingService;
use OCA\Libresign\Service\Payment\ProviderAmountNormaliser;
use OCA\Libresign\Service\ProductService;
use OCA\Libresign\Tests\Unit\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Integration-style coverage of the stale sponsorship-pointer scenario in the
 * real startPayment() reuse path: an abandoned pending CARD credit purchase
 * holds context.sponsorshipWorkflow.fileUuid = A, and a later generic CARD
 * credit purchase (no pointer) reuses it. The reuse must strip the stale
 * pointer from the persisted metadata, reuse (not expire/recreate) the payment,
 * and leave every provider/financial identifier untouched.
 */
final class PaymentInitiationServiceTest extends TestCase {
	private PaymentRepository&MockObject $paymentRepository;
	private PaymentResponseFactory&MockObject $responseFactory;
	private PaymentLifecycleService&MockObject $lifecycleService;
	private PaymentRoutingService&MockObject $routingService;
	private LoggerInterface&MockObject $logger;
	private PaymentInitiationService $service;

	public function setUp(): void {
		parent::setUp();
		$this->paymentRepository = $this->createMock(PaymentRepository::class);
		$this->responseFactory = $this->createMock(PaymentResponseFactory::class);
		$this->lifecycleService = $this->createMock(PaymentLifecycleService::class);
		$this->routingService = $this->createMock(PaymentRoutingService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new PaymentInitiationService(
			$this->paymentRepository,
			$this->createMock(PaymentFactory::class),
			$this->responseFactory,
			$this->lifecycleService,
			$this->createMock(MobileMoneyService::class),
			$this->createMock(CardService::class),
			$this->routingService,
			$this->createMock(FxEngineService::class),
			$this->createMock(ProviderAmountNormaliser::class),
			$this->createMock(ProductService::class),
			$this->createMock(PaymentDateTimeHelper::class),
			$this->logger,
		);
	}

	private function cardRoute(): MnoRoutingResultDTO {
		return new MnoRoutingResultDTO(
			capability: PaymentCapability::CARD,
			preferredProvider: PaymentProvider::DPO,
			mnoKey: null,
			mode: PaymentFlowMode::INSTRUCTIONS,
			currency: null,
			altCurrency: null,
			minAmount: null,
			maxAmount: null,
			supportsDecimals: null,
			confidence: ResolutionConfidence::HIGH,
			notes: null,
			country: null,
			region: null,
		);
	}

	private function pendingCardCreditPurchaseWithPointer(string $fileUuid): Payment {
		$meta = (new PaymentMetadataDTO(
			updatedAt: null,
			preferredProvider: 'dpo',
			executedProvider: 'dpo',
			flow: 'redirect',
			method: 'card',
			redirectUrl: 'https://app.example.com/f/payment/return',
			selected: SelectedMnoDTO::fromArray([]),
			suggested: SuggestedMnoDTO::fromArray([]),
			selection: SelectionDTO::fromArray([]),
			autoCharge: AutoChargeDTO::fromArray([]),
			confidence: null,
			alreadyCharged: false,
			instructions: null,
			providerExecutionState: null,
			providerPayload: ProviderPayloadDTO::fromArray([]),
			context: [],
			providerError: null,
			returnUrl: 'https://app.example.com/f/request',
		))->withSponsorshipWorkflowFileUuid($fileUuid);

		$payment = new Payment();
		$payment->setUserId('user-1');
		$payment->setProvider('dpo');
		$payment->setProviderReference('dpo-ref-123');
		$payment->setPaymentAttemptId('attempt-1');
		$payment->setAmount(1000);
		$payment->setProductCode('SIGN_CREDIT');
		$payment->setPaymentStatus(PaymentStatus::PENDING);
		$payment->setPaymentPurpose(PaymentPurpose::CREDIT_PURCHASE);
		$payment->setProviderMetadataObject($meta);
		return $payment;
	}

	private function genericCardCreditPurchaseDto(): StartPaymentDTO {
		return new StartPaymentDTO(
			userEmail: 'user@example.com',
			signUuid: null,
			signRequestId: null,
			purpose: PaymentPurpose::CREDIT_PURCHASE,
			redirectUrl: 'https://app.example.com/f/payment/return',
			userId: 'user-1',
			provider: null,
			productCode: 'SIGN_CREDIT',
			paymentMethod: PaymentMethod::CARD,
			quantity: 1,
			callbackUrl: null,
			paymentAttemptId: null,
			phoneNumber: null,
			returnUrl: 'https://app.example.com/f/request',
			sponsorshipWorkflowFileUuid: null,
		);
	}

	public function testReuseClearsStalePointerWithoutExpiringOrChangingIdentifiers(): void {
		$pending = $this->pendingCardCreditPurchaseWithPointer('file-A');

		$this->routingService->method('resolveCard')->willReturn($this->cardRoute());
		$this->paymentRepository->method('findLatestPendingCreditPurchaseByUserId')
			->with('user-1', 'SIGN_CREDIT')
			->willReturn($pending);
		$this->lifecycleService->method('hasPaymentExpired')->willReturn(false);
		$this->lifecycleService->method('isPendingStaleForRetry')->willReturn(false);

		// The payment must be reused, never expired/recreated.
		$this->lifecycleService->expects($this->never())->method('expirePayment');

		// Metadata is persisted with the stale pointer removed; identifiers intact.
		$this->paymentRepository->expects($this->once())
			->method('update')
			->with($this->callback(function (Payment $p): bool {
				return $p->getProviderMetadataObject()->getSponsorshipWorkflowFileUuid() === null
					&& $p->getProviderReference() === 'dpo-ref-123'
					&& $p->getPaymentAttemptId() === 'attempt-1'
					&& $p->getAmount() === 1000
					&& $p->getPaymentStatus() === PaymentStatus::PENDING;
			}));

		$expected = $this->createMock(ExistingPaymentResultDTO::class);
		$this->responseFactory->expects($this->once())
			->method('buildExistingPaymentResponse')
			->with($pending)
			->willReturn($expected);

		$result = $this->service->startPayment($this->genericCardCreditPurchaseDto());

		self::assertSame($expected, $result);
		self::assertNull($pending->getProviderMetadataObject()->getSponsorshipWorkflowFileUuid());
	}
}
