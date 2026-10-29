<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Operations\Analytics;

use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\Operations\Analytics\OperationsAccessService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\AppFramework\Http;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;

final class OperationsAccessServiceTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private IGroupManager&MockObject $groupManager;
	private OperationsAccessService $service;

	public function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->service = new OperationsAccessService($this->appConfig, $this->groupManager);
	}

	private function user(string $uid = 'u1'): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	public function testDefaultsAreOffAndAdminOnly(): void {
		$this->appConfig->expects($this->once())->method('getValueBool')
			->with('libresign', 'operations_dashboard_enabled', false)->willReturn(false);
		$this->appConfig->expects($this->once())->method('getValueArray')
			->with('libresign', 'operations_groups', ['admin'])->willReturnArgument(2);

		$this->assertFalse($this->service->isEnabled());
		$this->assertSame(['admin'], $this->service->getOperationsGroups());
	}

	public function testMistypedFlagFailsClosed(): void {
		$this->appConfig->method('getValueBool')->willThrowException(new AppConfigTypeConflictException('x'));
		$this->assertFalse($this->service->isEnabled());
	}

	public function testMistypedGroupsFallBackToAdminOnly(): void {
		$this->appConfig->method('getValueArray')->willThrowException(new AppConfigTypeConflictException('x'));
		$this->assertSame(['admin'], $this->service->getOperationsGroups());
	}

	public function testGroupListIsNormalized(): void {
		$this->appConfig->method('getValueArray')->willReturn(['ops', '', 7]);
		$this->assertSame(['ops', '7'], $this->service->getOperationsGroups());
	}

	public function testEmptyGroupListStillAllowsAdminButNoOneElse(): void {
		$this->appConfig->method('getValueArray')->willReturn([]);
		$this->groupManager->method('getUserGroupIds')->willReturn(['admin', 'ops']);
		$this->groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === 'root');

		$this->assertTrue($this->service->isAuthorized($this->user('root')));
		$this->assertFalse($this->service->isAuthorized($this->user('bob')));
	}

	public function testAdminCanAccessWhenEnabledEvenIfNotInConfiguredGroups(): void {
		$this->appConfig->method('getValueBool')->willReturn(true);
		$this->appConfig->method('getValueArray')->willReturn(['gopaperless-operations']);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->groupManager->method('getUserGroupIds')->willReturn(['admin']);

		$this->assertTrue($this->service->canAccess($this->user('root')));
		$this->service->assertAccess($this->user('root'));
	}

	public function testNullUserIsNeverAuthorized(): void {
		$this->assertFalse($this->service->isAuthorized(null));
	}

	public function testCanAccessRequiresFlagAndAuthorization(): void {
		$this->appConfig->method('getValueBool')->willReturn(false);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->assertFalse($this->service->canAccess($this->user()));
	}

	public function testAssertAccessThrows403(): void {
		$this->appConfig->method('getValueBool')->willReturn(true);
		$this->appConfig->method('getValueArray')->willReturn(['admin']);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroupIds')->willReturn([]);

		try {
			$this->service->assertAccess($this->user());
			$this->fail('Expected LibresignException');
		} catch (LibresignException $e) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $e->getCode());
		}
	}
}
