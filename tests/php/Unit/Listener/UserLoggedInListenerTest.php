<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Listener;

use OCA\Libresign\Listener\UserLoggedInListener;
use OCA\Libresign\Service\AccountService;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\UserLoggedInEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserLoggedInListenerTest extends TestCase {
	private AccountService&MockObject $accountService;
	private LoggerInterface&MockObject $logger;
	private UserLoggedInListener $listener;

	protected function setUp(): void {
		$this->accountService = $this->createMock(AccountService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new UserLoggedInListener(
			$this->accountService,
			$this->logger,
		);
	}

	private function event(IUser $user): UserLoggedInEvent {
		return new UserLoggedInEvent($user, $user->getUID(), null, false);
	}

	public function testRecoversMissingEmailOnLogin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('me@example.com');

		$this->accountService
			->expects($this->once())
			->method('recoverMissingEmailFromUid')
			->with($user)
			->willReturn(AccountService::EMAIL_RECOVERY_RECOVERED);

		$this->listener->handle($this->event($user));
	}

	public function testIgnoresUnrelatedEvent(): void {
		$this->accountService->expects($this->never())->method('recoverMissingEmailFromUid');

		$this->listener->handle(new Event());
	}

	public function testSwallowsFailureSoLoginIsNeverInterrupted(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('me@example.com');

		$this->accountService
			->method('recoverMissingEmailFromUid')
			->willThrowException(new \RuntimeException('account api blew up'));

		$this->logger->expects($this->once())->method('error');

		// No exception must escape the handler into the authentication path.
		$this->listener->handle($this->event($user));
	}
}
