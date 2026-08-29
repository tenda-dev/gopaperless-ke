<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\AdminController;
use OCA\Libresign\Service\Payment\PhoneOverrideAdminService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Covers only the phone-override endpoints AdminController now owns
 * (migrated from the removed PhoneMnoOverrideController).
 */
final class AdminControllerPhoneOverrideTest extends TestCase {
	private PhoneOverrideAdminService&MockObject $service;
	private IUserSession&MockObject $userSession;
	private AdminController $controller;

	public function setUp(): void {
		parent::setUp();

		$this->service = $this->createMock(PhoneOverrideAdminService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new AdminController(
			$this->createMock(\OCP\IRequest::class),
			$this->createMock(\OCP\IAppConfig::class),
			$this->createMock(\OCA\Libresign\Service\Install\ConfigureCheckService::class),
			$this->createMock(\OCA\Libresign\Service\Install\InstallService::class),
			$this->createMock(\OCA\Libresign\Handler\CertificateEngine\CertificateEngineFactory::class),
			$this->createMock(\OCP\IEventSourceFactory::class),
			$this->createMock(\OCA\Libresign\Service\SignatureTextService::class),
			$this->createMock(\OCP\IL10N::class),
			$this->createMock(\OCP\ISession::class),
			$this->createMock(\OCA\Libresign\Service\SignatureBackgroundService::class),
			$this->createMock(\OCA\Libresign\Service\CertificatePolicyService::class),
			$this->createMock(\OCA\Libresign\Service\Certificate\ValidateService::class),
			$this->createMock(\OCA\Libresign\Service\ReminderService::class),
			$this->createMock(\OCA\Libresign\Service\FooterService::class),
			$this->createMock(\OCA\Libresign\Service\DocMdp\ConfigService::class),
			$this->createMock(\OCA\Libresign\Service\IdentifyMethodService::class),
			$this->createMock(\OCA\Libresign\Db\FileMapper::class),
			$this->createMock(\OCP\IGroupManager::class),
			$this->userSession,
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->service,
		);
	}

	public function testGetPhoneOverridesReturnsList(): void {
		$this->service->method('listAll')->willReturn([['id' => 1, 'phone' => '+254712345678']]);

		$response = $this->controller->getPhoneOverrides();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([['id' => 1, 'phone' => '+254712345678']], $response->getData()['overrides']);
	}

	public function testCreateDelegatesWithMnoProviderAndAdminUid(): void {
		$this->service->expects($this->once())
			->method('createException')
			->with('+254712345678', 'safaricom', 'daraja', 'admin')
			->willReturn(['status' => 'created', 'override' => ['id' => 2]]);

		$response = $this->controller->createPhoneOverride('+254712345678', 'safaricom', 'daraja');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('created', $response->getData()['status']);
	}

	public function testCreateValidationErrorMapsTo400(): void {
		$this->service->method('createException')
			->willThrowException(new \InvalidArgumentException('Select a supported mobile network.'));

		$response = $this->controller->createPhoneOverride('+254712345678', 'nope', 'daraja');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('Select a supported mobile network.', $response->getData()['error']);
	}

	public function testCreateDuplicateMapsTo409(): void {
		$this->service->method('createException')
			->willReturn(['status' => 'duplicate', 'override' => ['id' => 3]]);

		$response = $this->controller->createPhoneOverride('+254712345678', 'airtel', 'dpo');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testUpdateReturnsUpdated(): void {
		$this->service->method('update')
			->with(4, null, null, null, false)
			->willReturn(['status' => 'updated', 'override' => ['id' => 4, 'active' => false]]);

		$response = $this->controller->updatePhoneOverride(4, null, null, null, false);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertFalse($response->getData()['override']['active']);
	}

	public function testUpdateDuplicateMapsTo409(): void {
		$this->service->method('update')
			->willReturn(['status' => 'duplicate', 'override' => ['id' => 5]]);

		$response = $this->controller->updatePhoneOverride(4, '+254799000111', null, null, null);

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testUpdateUnknownMapsTo404(): void {
		$this->service->method('update')
			->willThrowException(new DoesNotExistException('missing'));

		$response = $this->controller->updatePhoneOverride(999, null, null, null, true);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testUpdateBadInputMapsTo400(): void {
		$this->service->method('update')
			->willThrowException(new \InvalidArgumentException('Select a supported payment provider.'));

		$response = $this->controller->updatePhoneOverride(4, null, null, 'bogus', null);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testDeleteReturnsDeleted(): void {
		$this->service->expects($this->once())->method('delete')->with(7);

		$response = $this->controller->deletePhoneOverride(7);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('deleted', $response->getData()['status']);
	}

	public function testDeleteUnknownMapsTo404(): void {
		$this->service->method('delete')
			->willThrowException(new DoesNotExistException('missing'));

		$response = $this->controller->deletePhoneOverride(999);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testEndpointsAreAdminGated(): void {
		foreach (['getPhoneOverrides', 'createPhoneOverride', 'updatePhoneOverride', 'deletePhoneOverride'] as $method) {
			$attrs = (new \ReflectionMethod(AdminController::class, $method))
				->getAttributes(NoAdminRequired::class);
			self::assertCount(0, $attrs, "$method must not be #[NoAdminRequired]");
		}
	}
}
