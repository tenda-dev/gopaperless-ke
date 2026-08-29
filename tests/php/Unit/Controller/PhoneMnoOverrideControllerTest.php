<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\PhoneMnoOverrideController;
use OCA\Libresign\Service\Payment\PhoneOverrideAdminService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class PhoneMnoOverrideControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private PhoneOverrideAdminService&MockObject $service;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private PhoneMnoOverrideController $controller;

	public function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(PhoneOverrideAdminService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new PhoneMnoOverrideController(
			$this->request,
			$this->service,
			$this->userSession,
			$this->logger,
		);
	}

	public function testIndexReturnsList(): void {
		$this->service->method('listAll')->willReturn([['id' => 1, 'phone' => '+254712345678']]);

		$response = $this->controller->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([['id' => 1, 'phone' => '+254712345678']], $response->getData()['overrides']);
	}

	public function testCreateDelegatesWithAdminUidAndReturnsResult(): void {
		$this->service->expects($this->once())
			->method('createSafaricomException')
			->with('+254712345678', 'admin')
			->willReturn(['status' => 'created', 'override' => ['id' => 2]]);

		$response = $this->controller->create('+254712345678');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('created', $response->getData()['status']);
	}

	public function testCreateValidationErrorMapsTo400(): void {
		$this->service->method('createSafaricomException')
			->willThrowException(new \InvalidArgumentException('Enter a phone number.'));

		$response = $this->controller->create('');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('Enter a phone number.', $response->getData()['error']);
	}

	public function testCreateDuplicateMapsTo409(): void {
		$this->service->method('createSafaricomException')
			->willReturn(['status' => 'duplicate', 'override' => ['id' => 3]]);

		$response = $this->controller->create('+254712345678');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testUpdateReturnsUpdated(): void {
		$this->service->method('update')->with(4, null, false)
			->willReturn(['status' => 'updated', 'override' => ['id' => 4, 'active' => false]]);

		$response = $this->controller->update(4, null, false);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertFalse($response->getData()['override']['active']);
	}

	public function testUpdatePhoneDuplicateMapsTo409(): void {
		$this->service->method('update')
			->willReturn(['status' => 'duplicate', 'override' => ['id' => 5]]);

		$response = $this->controller->update(4, '+254799000111', null);

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testUpdateUnknownMapsTo404(): void {
		$this->service->method('update')
			->willThrowException(new DoesNotExistException('missing'));

		$response = $this->controller->update(999, null, true);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testUpdateBadPhoneMapsTo400(): void {
		$this->service->method('update')
			->willThrowException(new \InvalidArgumentException('bad'));

		$response = $this->controller->update(4, '+12', null);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testDestroyDeletes(): void {
		$this->service->expects($this->once())->method('delete')->with(7);

		$response = $this->controller->destroy(7);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['success']);
	}

	public function testDestroyUnknownMapsTo404(): void {
		$this->service->method('delete')
			->willThrowException(new DoesNotExistException('missing'));

		$response = $this->controller->destroy(999);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	/**
	 * Authorization posture: none of the endpoints opt out of admin with
	 * #[NoAdminRequired], so Nextcloud's middleware enforces site-admin.
	 */
	public function testEndpointsAreAdminGated(): void {
		foreach (['index', 'create', 'update', 'destroy'] as $method) {
			$attrs = (new \ReflectionMethod(PhoneMnoOverrideController::class, $method))
				->getAttributes(NoAdminRequired::class);
			self::assertCount(0, $attrs, "$method must not be #[NoAdminRequired]");
		}
	}
}
