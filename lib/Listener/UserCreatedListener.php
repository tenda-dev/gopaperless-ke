<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Listener;

use OCA\Libresign\AppInfo\Application;
use OCP\AppFramework\Services\IAppConfig;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\User\Events\UserCreatedEvent;
use Psr\Log\LoggerInterface;

/**
 * Put every new account in the group that is allowed to request signing.
 *
 * `AccountService::canRequestSign()` refuses anyone outside
 * `groups_request_sign`, and an empty list refuses everyone rather than
 * allowing them — so a freshly provisioned account can do nothing until it is
 * put in a group. On this deployment every user of GoPaperless is meant to be
 * able to send documents for signature, which makes "no groups" the wrong
 * default for all of them, every time.
 *
 * It belongs here rather than in any one client. Accounts arrive from the
 * signing applet's bearer provisioning, from the website's SSO handoff, from
 * the WhatsApp flow and from `occ`; a listener on user creation covers all of
 * them, and each client solving it separately would be four places to forget.
 *
 * Never grants `admin`. The group is created if it does not exist, so a fresh
 * instance provisions correctly without anyone remembering to make it first.
 *
 * @template-implements IEventListener<UserCreatedEvent>
 */
class UserCreatedListener implements IEventListener {
	/** Overridable per instance: `occ config:app:set libresign default_signer_group --value=...` */
	private const DEFAULT_GROUP = 'signers';

	public function __construct(
		private IGroupManager $groupManager,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof UserCreatedEvent)) {
			return;
		}
		$user = $event->getUser();
		if (!$user->getUID()) {
			return;
		}

		$groupId = trim($this->appConfig->getAppValueString('default_signer_group', self::DEFAULT_GROUP));
		if ($groupId === '' || $groupId === 'admin') {
			return;
		}

		try {
			$group = $this->groupManager->get($groupId) ?? $this->groupManager->createGroup($groupId);
			if ($group === null) {
				$this->logger->error('Could not create the default signer group', ['group' => $groupId]);
				return;
			}
			if ($group->inGroup($user)) {
				return;
			}
			$group->addUser($user);
			$this->logger->info('Added new account to the default signer group', [
				'user' => $user->getUID(),
				'group' => $groupId,
			]);
		} catch (\Throwable $e) {
			// Never fail the account creation over this. A user who lands
			// outside the group gets a clear refusal the first time they try to
			// send something, which is recoverable; a login that dies half way
			// through provisioning is not.
			$this->logger->error('Could not add a new account to the default signer group', [
				'user' => $user->getUID(),
				'group' => $groupId,
				'exception' => $e,
			]);
		}
	}
}
