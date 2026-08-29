<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\SignatureProfile;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\SignatureProfile\SignatureProfileService;
use OCA\Libresign\Service\SignatureProfile\ValueObject\SignatureProfile;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class SignatureProfileServiceTest extends \OCA\Libresign\Tests\Unit\TestCase {
	private IAppConfig $appConfig;
	private IGroupManager&MockObject $groupManager;
	private IUserManager&MockObject $userManager;
	private LoggerInterface&MockObject $logger;
	private SignatureProfileService $service;

	public function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->getMockAppConfigWithReset();
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new SignatureProfileService(
			$this->appConfig,
			$this->groupManager,
			$this->userManager,
			$this->logger,
		);
	}

	public function testResolveForRequesterReturnsDefaultWhenFlagDisabled(): void {
		$profile = $this->service->resolveForRequester('user1', false);

		$this->assertTrue($profile->shouldRenderFooter());
		$this->assertTrue($profile->shouldRenderQrCode());
		$this->assertTrue($profile->shouldRenderStamp());
		$this->assertTrue($profile->shouldRenderAuditInfo());
	}

	public function testResolveForRequesterReturnsDefaultWhenUserHasNoGroups(): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('user1')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->with($user)->willReturn([]);

		$profile = $this->service->resolveForRequester('user1', true);

		$this->assertEquals(SignatureProfile::default(), $profile);
	}

	public function testResolveForRequesterPicksLowestSortedGroupId(): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('user1')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->with($user)->willReturn(['editors', 'admins']);
		$this->appConfig->setValueArray(Application::APP_ID, 'appearance_profiles', [
			'editors' => ['footer' => false],
			'admins' => ['qr' => false],
		]);
		$this->groupManager->method('groupExists')->willReturnCallback(fn (string $groupId): bool => in_array($groupId, ['editors', 'admins'], true));

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('multiple configured appearance-profile groups'),
				$this->callback(fn (array $context): bool => $context['resolvedGroup'] === 'admins'),
			);

		$profile = $this->service->resolveForRequester('user1', true);

		$this->assertTrue($profile->shouldRenderFooter());
		$this->assertFalse($profile->shouldRenderQrCode());
		$this->assertTrue($profile->shouldRenderStamp());
		$this->assertTrue($profile->shouldRenderAuditInfo());
	}

	public function testReconcileConfiguredGroupsPrunesDeletedGroups(): void {
		$this->appConfig->setValueArray(Application::APP_ID, 'appearance_profiles', [
			'kept' => ['footer' => false],
			'gone' => ['qr' => false],
		]);
		$this->groupManager->method('groupExists')
			->willReturnCallback(fn (string $groupId): bool => $groupId === 'kept');

		$result = $this->service->reconcileConfiguredGroups();

		$this->assertSame(['kept'], array_keys($result['profiles']));
		$this->assertSame(['gone'], $result['removed']);
		$this->assertSame(
			['kept' => ['footer' => false]],
			$this->appConfig->getValueArray(Application::APP_ID, 'appearance_profiles', []),
		);
	}
}
