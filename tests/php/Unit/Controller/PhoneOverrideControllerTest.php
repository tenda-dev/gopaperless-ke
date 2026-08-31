<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Controller\PhoneOverrideController;
use OCA\Libresign\Service\Payment\PhoneOverrideAdminService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Covers the phone-override admin endpoints.
 */
final class PhoneOverrideControllerTest extends TestCase {
	private PhoneOverrideAdminService&MockObject $service;
	private IAppConfig&MockObject $appConfig;
	private IGroupManager&MockObject $groupManager;
	private IUserSession&MockObject $userSession;
	private PhoneOverrideController $controller;

	public function setUp(): void {
		parent::setUp();

		$this->service = $this->createMock(PhoneOverrideAdminService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text) => $text);

		$this->controller = new PhoneOverrideController(
			$this->createMock(IRequest::class),
			$this->appConfig,
			$this->groupManager,
			$this->userSession,
			$l10n,
			$this->createMock(LoggerInterface::class),
			$this->service,
		);
	}

	private function enableFeatureAndAdmin(): void {
		$this->appConfig->method('getValueBool')
			->willReturnCallback(function (string $app, string $key): bool {
				return $app === Application::APP_ID && $key === 'phone_mno_routing_v2_enabled';
			});
		$this->groupManager->method('isAdmin')->willReturn(true);
	}

	public function testGetPhoneOverridesReturnsList(): void {
		$this->enableFeatureAndAdmin();
		$this->service->method('listAll')->willReturn([['id' => 1, 'phone' => '+254712345678']]);

		$response = $this->controller->getPhoneOverrides();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([['id' => 1, 'phone' => '+254712345678']], $response->getData()['overrides']);
	}

	public function testCreateDelegatesWithMnoProviderAndAdminUid(): void {
		$this->enableFeatureAndAdmin();
		$this->service->expects($this->once())
			->method('createException')
			->with('+254712345678', 'safaricom', 'daraja', 'admin')
			->willReturn(['status' => 'created', 'override' => ['id' => 2]]);

		$response = $this->controller->createPhoneOverride('+254712345678', 'safaricom', 'daraja');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('created', $response->getData()['status']);
	}

	public function testCreateValidationErrorMapsTo400AndDoesNotLeakMessage(): void {
		$this->enableFeatureAndAdmin();
		$this->service->method('createException')
			->willThrowException(new \InvalidArgumentException('Select a supported mobile network.'));

		$response = $this->controller->createPhoneOverride('+254712345678', 'nope', 'daraja');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertNotSame('Select a supported mobile network.', $response->getData()['error']);
	}

	public function testCreateDuplicateMapsTo409(): void {
		$this->enableFeatureAndAdmin();
		$this->service->method('createException')
			->willReturn(['status' => 'duplicate', 'override' => ['id' => 3]]);

		$response = $this->controller->createPhoneOverride('+254712345678', 'airtel', 'dpo');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testUpdateReturnsUpdated(): void {
		$this->enableFeatureAndAdmin();
		$this->service->method('update')
			->with(4, null, null, null, false)
			->willReturn(['status' => 'updated', 'override' => ['id' => 4, 'active' => false]]);

		$response = $this->controller->updatePhoneOverride(4, null, null, null, false);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertFalse($response->getData()['override']['active']);
	}

	public function testUpdateDuplicateMapsTo409(): void {
		$this->enableFeatureAndAdmin();
		$this->service->method('update')
			->willReturn(['status' => 'duplicate', 'override' => ['id' => 5]]);

		$response = $this->controller->updatePhoneOverride(4, '+254799000111', null, null, null);

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testUpdateUnknownMapsTo404(): void {
		$this->enableFeatureAndAdmin();
		$this->service->method('update')
			->willThrowException(new DoesNotExistException('missing'));

		$response = $this->controller->updatePhoneOverride(999, null, null, null, true);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testUpdateBadInputMapsTo400(): void {
		$this->enableFeatureAndAdmin();
		$this->service->method('update')
			->willThrowException(new \InvalidArgumentException('Select a supported payment provider.'));

		$response = $this->controller->updatePhoneOverride(4, null, null, 'bogus', null);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertNotSame('Select a supported payment provider.', $response->getData()['error']);
	}

	public function testDeleteReturnsDeleted(): void {
		$this->enableFeatureAndAdmin();
		$this->service->expects($this->once())->method('delete')->with(7);

		$response = $this->controller->deletePhoneOverride(7);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('deleted', $response->getData()['status']);
	}

	public function testDeleteUnknownMapsTo404(): void {
		$this->enableFeatureAndAdmin();
		$this->service->method('delete')
			->willThrowException(new DoesNotExistException('missing'));

		$response = $this->controller->deletePhoneOverride(999);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testFeatureFlagDisabledReturns403(): void {
		$this->appConfig->method('getValueBool')->willReturn(false);

		$response = $this->controller->getPhoneOverrides();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testNonAdminUserReturns403(): void {
		$this->appConfig->method('getValueBool')->willReturn(true);
		$this->groupManager->method('isAdmin')->willReturn(false);

		$response = $this->controller->getPhoneOverrides();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}
}
