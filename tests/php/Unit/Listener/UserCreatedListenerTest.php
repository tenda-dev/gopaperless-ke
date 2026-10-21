<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Listener;

use OCA\Libresign\Listener\UserCreatedListener;
use OCA\Libresign\Service\AccountService;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\UserCreatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class UserCreatedListenerTest extends TestCase {
	private AccountService&MockObject $accountService;
	private LoggerInterface&MockObject $logger;
	private UserCreatedListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->accountService = $this->createMock(AccountService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new UserCreatedListener(
			$this->accountService,
			$this->logger,
		);
	}

	public function testIgnoresUnrelatedEvents(): void {
		$this->accountService->expects($this->never())->method('ensureDefaultSignerGroup');
		$this->listener->handle(new Event());
	}

	public function testDelegatesTheGrantForTheCreatedUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('newbie');

		$this->accountService
			->expects($this->once())
			->method('ensureDefaultSignerGroup')
			->with($user)
			->willReturn(AccountService::SIGNER_GROUP_GRANTED);

		$this->listener->handle(new UserCreatedEvent($user, ''));
	}

	public function testGrantFailuresNeverBreakAccountCreation(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('newbie');

		$this->accountService
			->method('ensureDefaultSignerGroup')
			->willThrowException(new \RuntimeException('database gone'));
		$this->logger->expects($this->once())->method('error');

		// No exception must escape the handler.
		$this->listener->handle(new UserCreatedEvent($user, ''));
	}
}
