<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Listener;

use OCA\Libresign\Listener\UserCreatedListener;
use OCP\AppFramework\Services\IAppConfig;
use OCP\EventDispatcher\Event;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\User\Events\UserCreatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class UserCreatedListenerTest extends TestCase {
	private IGroupManager&MockObject $groupManager;
	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;
	private UserCreatedListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new UserCreatedListener(
			$this->groupManager,
			$this->appConfig,
			$this->logger,
		);
	}

	public function testIgnoresUnrelatedEvents(): void {
		$this->appConfig->expects($this->never())
			->method('getAppValueString');
		$this->groupManager->expects($this->never())
			->method('get');

		$this->listener->handle(new Event());
	}

	public function testDoesNothingWhileSecurySignIsDisabled(): void {
		$this->appConfig->method('getAppValueString')
			->with('securysign_provider_id', '0')
			->willReturn('0');
		$this->groupManager->expects($this->never())
			->method('get');
		$this->groupManager->expects($this->never())
			->method('createGroup');

		$this->listener->handle($this->newUserCreatedEvent('newbie'));
	}

	public function testAddsNewAccountToTheDefaultGroup(): void {
		$this->flagEnabled();
		$this->appConfig->method('getAppValueString')
			->with('default_signer_group', 'signers')
			->willReturn('signers');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('newbie');
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->with($user)->willReturn(false);
		$group->expects($this->once())->method('addUser')->with($user);
		$this->groupManager->method('get')->with('signers')->willReturn($group);
		$this->groupManager->expects($this->never())->method('createGroup');

		$this->listener->handle($this->newUserCreatedEventFrom($user));
	}

	public function testCreatesTheGroupWhenMissing(): void {
		$this->flagEnabled();
		$this->appConfig->method('getAppValueString')
			->willReturn('signers');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('newbie');
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturn(false);
		$group->expects($this->once())->method('addUser')->with($user);
		$this->groupManager->method('get')->with('signers')->willReturn(null);
		$this->groupManager->expects($this->once())
			->method('createGroup')
			->with('signers')
			->willReturn($group);

		$this->listener->handle($this->newUserCreatedEventFrom($user));
	}

	public function testRefusesToTouchTheAdminGroup(): void {
		$this->flagEnabled();
		$this->appConfig->method('getAppValueString')
			->willReturn('admin');
		$this->groupManager->expects($this->never())
			->method('get');

		$this->listener->handle($this->newUserCreatedEvent('newbie'));
	}

	public function testGroupFailuresNeverBreakAccountCreation(): void {
		$this->flagEnabled();
		$this->appConfig->method('getAppValueString')
			->willReturn('signers');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('newbie');
		$this->groupManager->method('get')->willThrowException(new \RuntimeException('database gone'));
		$this->logger->expects($this->once())->method('error');

		$this->listener->handle($this->newUserCreatedEventFrom($user));
	}

	private function flagEnabled(): void {
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(static fn (string $key, string $default): string => $key === 'securysign_provider_id' ? '1' : $default);
	}

	private function newUserCreatedEvent(string $uid): UserCreatedEvent {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $this->newUserCreatedEventFrom($user);
	}

	private function newUserCreatedEventFrom(IUser $user): UserCreatedEvent {
		return new UserCreatedEvent($user, '');
	}
}
