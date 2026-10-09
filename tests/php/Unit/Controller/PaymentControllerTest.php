<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\PaymentController;
use OCA\Libresign\Db\Payment as PaymentEntity;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Enum\PaymentCapability;
use OCA\Libresign\Enum\PaymentFlowMode;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\PhoneMnoResolutionSource;
use OCA\Libresign\Enum\ResolutionConfidence;
use OCA\Libresign\Service\Payment\DTO\AutoChargeDTO;
use OCA\Libresign\Service\Payment\DTO\MnoRoutingResultDTO;
use OCA\Libresign\Service\Payment\DTO\PaymentCountryContextDTO;
use OCA\Libresign\Service\Payment\DTO\PaymentRoutingResultDTO;
use OCA\Libresign\Service\Payment\DTO\PhoneMnoIdentityDTO;
use OCA\Libresign\Service\Payment\PaymentService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class PaymentControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private PaymentService&MockObject $paymentService;
	private LoggerInterface&MockObject $logger;
	private IUserSession&MockObject $userSession;
	private SignRequestMapper&MockObject $signRequestMapper;
	private IAppConfig&MockObject $appConfig;
	private IGroupManager&MockObject $groupManager;
	private IL10N&MockObject $l10n;
	private PaymentController $controller;

	public function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->paymentService = $this->createMock(PaymentService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->signRequestMapper = $this->createMock(SignRequestMapper::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text, ...$args): string => vsprintf($text, $args));

		$this->controller = new PaymentController(
			request: $this->request,
			paymentService: $this->paymentService,
			logger: $this->logger,
			userSession: $this->userSession,
			signRequestMapper: $this->signRequestMapper,
			appConfig: $this->appConfig,
			groupManager: $this->groupManager,
			l10n: $this->l10n,
		);
	}

	private function givenAuthenticatedUser(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testVerifyReturnsUnauthorizedWhenAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->verify('ref-123');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame('Unauthorized', $response->getData()['error']);
	}

	public function testVerifyDeniesAccessToOtherUsersPayment(): void {
		$this->givenAuthenticatedUser('owner');

		$this->paymentService
			->method('assertPaymentOwnership')
			->with('ref-123', 'owner')
			->willThrowException(new \RuntimeException('Access Denied'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Access Denied');

		$this->controller->verify('ref-123');
	}

	public function testStatusReturnsUnauthorizedWhenAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->status('ref-123');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame('Unauthorized', $response->getData()['error']);
	}

	public function testQueryDarajaReturnsUnauthorizedWhenAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->queryDaraja('ref-123');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame('Unauthorized', $response->getData()['error']);
	}

	public function testQueryDarajaReturnsErrorForOtherUsersPayment(): void {
		$this->givenAuthenticatedUser('owner');

		$this->paymentService
			->method('assertPaymentOwnership')
			->with('ref-123', 'owner')
			->willThrowException(new \RuntimeException('Access Denied'));

		$response = $this->controller->queryDaraja('ref-123');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('Access Denied', $response->getData()['error']);
	}

	public function testChargeMobileReturnsUnauthorizedWhenAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->chargeMobile('ref-123', '+254700000000');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame('Unauthorized', $response->getData()['error']);
	}

	public function testChargeMobileRejectsPhoneMismatch(): void {
		$this->givenAuthenticatedUser('owner');

		$payment = new PaymentEntity();
		$payment->setPhoneE164Digits('+254711111111');

		$this->paymentService
			->method('assertPaymentOwnership')
			->with('ref-123', 'owner')
			->willReturn($payment);

		$response = $this->controller->chargeMobile('ref-123', '+254700000000');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('Phone number does not match the payment', $response->getData()['error']);
	}

	public function testGetMobileOptionsReturnsUnauthorizedWhenAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->getMobileOptions('ref-123', 'KE');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame('Unauthorized', $response->getData()['error']);
	}

	public function testResolvePhoneReturnsUnauthorizedWhenAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->resolveMobilePaymentPhoneNumber('+254711000000');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame('Unauthorized', $response->getData()['error']);
	}

	public function testResolvePhoneForbidsWhenRoutingV2IsDisabled(): void {
		$this->givenAuthenticatedUser('user1');
		$this->appConfig->method('getValueBool')
			->with('libresign', 'phone_mno_routing_v2_enabled', false)
			->willReturn(false);

		$response = $this->controller->resolveMobilePaymentPhoneNumber('+254711000000');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testResolvePhoneOmitsAdminRoutingConfigForNonAdmins(): void {
		$this->givenAuthenticatedUser('user1');
		$this->appConfig->method('getValueBool')->willReturn(true);
		$this->groupManager->method('isAdmin')->with('user1')->willReturn(false);
		$this->paymentService->method('resolveMobilePaymentPhoneNumber')
			->willReturn($this->givenRoutingResult());

		$response = $this->controller->resolveMobilePaymentPhoneNumber('+254711000000');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertArrayNotHasKey('override', $response->getData()['result']['routing']);
		self::assertArrayNotHasKey('providerMnoKey', $response->getData()['result']['routing']);
	}

	public function testResolvePhoneIncludesAdminRoutingConfigForAdmins(): void {
		$this->givenAuthenticatedUser('admin1');
		$this->appConfig->method('getValueBool')->willReturn(true);
		$this->groupManager->method('isAdmin')->with('admin1')->willReturn(true);
		$this->paymentService->method('resolveMobilePaymentPhoneNumber')
			->willReturn($this->givenRoutingResult());

		$response = $this->controller->resolveMobilePaymentPhoneNumber('+254711000000');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('daraja', $response->getData()['result']['routing']['override']);
		self::assertSame('safaricom', $response->getData()['result']['routing']['providerMnoKey']);
	}

	public function testResolvePhoneIsSessionOnlyAndRateLimited(): void {
		$method = new \ReflectionMethod(PaymentController::class, 'resolveMobilePaymentPhoneNumber');
		$names = array_map(static fn (\ReflectionAttribute $a): string => $a->getName(), $method->getAttributes());

		self::assertNotContains(PublicPage::class, $names);
		self::assertContains(UserRateLimit::class, $names);
	}

	private function givenRoutingResult(): PaymentRoutingResultDTO {
		return new PaymentRoutingResultDTO(
			identity: new PhoneMnoIdentityDTO(
				valid: true,
				e164: '+254711000000',
				national: '0711000000',
				region: 'KE',
				country: 'KE',
				mno: 'safaricom',
				carrierHint: 'Safaricom',
				confidence: ResolutionConfidence::HIGH,
				source: PhoneMnoResolutionSource::DETECTION,
				verified: true,
			),
			route: new MnoRoutingResultDTO(
				capability: PaymentCapability::MOBILE_MONEY,
				preferredProvider: PaymentProvider::DARAJA,
				mnoKey: 'safaricom',
				mode: PaymentFlowMode::STK_PUSH,
				currency: 'KES',
				altCurrency: null,
				minAmount: 1.0,
				maxAmount: 150000.0,
				supportsDecimals: false,
				confidence: ResolutionConfidence::HIGH,
				notes: null,
				country: 'KE',
				region: 'KE',
			),
			countryContext: new PaymentCountryContextDTO(
				region: 'KE',
				country: 'kenya',
				currency: 'KES',
				altCurrency: null,
				supportsDecimals: false,
			),
			providerOverride: PaymentProvider::DARAJA,
			providerMnoKey: 'safaricom',
			autoCharge: new AutoChargeDTO(enabled: true),
		);
	}
}
