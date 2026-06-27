<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\PaymentController;
use OCA\Libresign\Db\Payment as PaymentEntity;
use OCA\Libresign\Service\Payment\PaymentService;
use OCA\Libresign\Service\SMS\SMSService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class PaymentControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private PaymentService&MockObject $paymentService;
	private LoggerInterface&MockObject $logger;
	private SMSService&MockObject $smsService;
	private IUserSession&MockObject $userSession;
	private PaymentController $controller;

	public function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->paymentService = $this->createMock(PaymentService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->smsService = $this->createMock(SMSService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new PaymentController(
			request: $this->request,
			paymentService: $this->paymentService,
			logger: $this->logger,
			smsService: $this->smsService,
			userSession: $this->userSession,
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
}
