<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Operations\Analytics\Report;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\Operations\Analytics\OperationsAccessService;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Mail\IMailer;

/**
 * Resolves who receives the period report.
 *
 * Recipients are the enabled members, with a valid email address, of the
 * groups EXPLICITLY stored under `operations_groups`. This deliberately does
 * not use OperationsAccessService::getOperationsGroups(): that method falls
 * back to ['admin'] when the key is unset, which would email every Nextcloud
 * administrator on an unconfigured install. Here an absent, mistyped or empty
 * list means "no recipients", and administrators receive the report only if
 * they belong to a configured group.
 *
 * Nextcloud's group API is flat: nested groups are not supported, so only
 * direct members of each configured group are included.
 */
class PeriodReportRecipientResolver {
	private const PAGE_SIZE = 100;
	/** Hard cap on users scanned across all groups, bounding mail volume. */
	public const MAX_USERS = 500;

	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private IMailer $mailer,
	) {
	}

	/**
	 * @return array{
	 *     recipients: list<array{email: string, name: string}>,
	 *     skipped: array{missingGroups: int, disabled: int, noEmail: int, invalidEmail: int, duplicates: int, capped: bool},
	 * }
	 */
	public function resolve(): array {
		$skipped = ['missingGroups' => 0, 'disabled' => 0, 'noEmail' => 0, 'invalidEmail' => 0, 'duplicates' => 0, 'capped' => false];
		$recipients = [];
		$seen = [];
		$scanned = 0;

		foreach ($this->configuredGroups() as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				$skipped['missingGroups']++;
				continue;
			}
			$offset = 0;
			while (true) {
				$users = $group->searchUsers('', self::PAGE_SIZE, $offset);
				foreach ($users as $user) {
					if ($scanned >= self::MAX_USERS) {
						$skipped['capped'] = true;
						break 3;
					}
					$scanned++;
					if (!$user->isEnabled()) {
						$skipped['disabled']++;
						continue;
					}
					$email = trim((string)$user->getEMailAddress());
					if ($email === '') {
						$skipped['noEmail']++;
						continue;
					}
					if (!$this->mailer->validateMailAddress($email)) {
						$skipped['invalidEmail']++;
						continue;
					}
					$key = strtolower($email);
					if (isset($seen[$key])) {
						$skipped['duplicates']++;
						continue;
					}
					$seen[$key] = true;
					$name = trim($user->getDisplayName());
					$recipients[] = ['email' => $email, 'name' => $name !== '' ? $name : $email];
				}
				if (count($users) < self::PAGE_SIZE) {
					break;
				}
				$offset += self::PAGE_SIZE;
			}
		}

		return ['recipients' => $recipients, 'skipped' => $skipped];
	}

	/**
	 * @return list<string>
	 */
	private function configuredGroups(): array {
		if (!$this->appConfig->hasKey(Application::APP_ID, OperationsAccessService::CONFIG_GROUPS)) {
			return [];
		}
		try {
			$groups = $this->appConfig->getValueArray(Application::APP_ID, OperationsAccessService::CONFIG_GROUPS, []);
		} catch (AppConfigTypeConflictException) {
			return [];
		}
		return array_values(array_unique(array_filter(
			array_map(static fn (mixed $group): string => trim((string)$group), $groups),
			static fn (string $group): bool => $group !== '',
		)));
	}
}
