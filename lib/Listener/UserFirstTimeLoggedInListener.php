<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Listener;

use OCA\Libresign\Service\AccountService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserFirstTimeLoggedInEvent;
use Psr\Log\LoggerInterface;

/**
 * Award the signup bonus on first login, including for users provisioned
 * outside AccountService's registration flows (e.g. OIDC).
 *
 * Delegates to the shared, idempotent grant operation to prevent duplicate
 * entitlements across registration, login, and recovery paths.
 *
 * Bonus failures must never interrupt authentication.
 *
 * @template-implements IEventListener<UserFirstTimeLoggedInEvent>
 */
class UserFirstTimeLoggedInListener implements IEventListener {
	public function __construct(
		private AccountService $accountService,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof UserFirstTimeLoggedInEvent)) {
			return;
		}

		$userId = $event->getUser()->getUID();
		if ($userId === '') {
			return;
		}

		try {
			$this->accountService->awardSignupBonus($userId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'[UserFirstTimeLoggedInListener] Failed to award signup bonus on first login',
				[
					'user' => $userId,
					'exception' => $e->getMessage(),
				]
			);
		}
	}
}
