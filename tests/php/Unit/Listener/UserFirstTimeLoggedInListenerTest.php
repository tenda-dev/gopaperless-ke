<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Listener;

use OCA\Libresign\Listener\UserFirstTimeLoggedInListener;
use OCA\Libresign\Service\AccountService;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\UserFirstTimeLoggedInEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserFirstTimeLoggedInListenerTest extends TestCase {
	private AccountService&MockObject $accountService;
	private LoggerInterface&MockObject $logger;
	private UserFirstTimeLoggedInListener $listener;

	protected function setUp(): void {
		$this->accountService = $this->createMock(AccountService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new UserFirstTimeLoggedInListener(
			$this->accountService,
			$this->logger,
		);
	}

	public function testAwardsSignupBonusForTheEventUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('oidc-newuser');

		$this->accountService
			->expects($this->once())
			->method('awardSignupBonus')
			->with('oidc-newuser');

		$this->listener->handle(new UserFirstTimeLoggedInEvent($user));
	}

	public function testIgnoresUnrelatedEvent(): void {
		$this->accountService
			->expects($this->never())
			->method('awardSignupBonus');

		$this->listener->handle(new Event());
	}

	public function testSkipsWhenUidIsEmpty(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('');

		$this->accountService
			->expects($this->never())
			->method('awardSignupBonus');

		$this->listener->handle(new UserFirstTimeLoggedInEvent($user));
	}

	public function testSwallowsGrantFailureSoLoginIsNeverInterrupted(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('oidc-newuser');

		$this->accountService
			->method('awardSignupBonus')
			->willThrowException(new \RuntimeException('grant blew up'));

		$this->logger->expects($this->once())->method('error');

		// No exception must escape the handler.
		$this->listener->handle(new UserFirstTimeLoggedInEvent($user));
	}
}
