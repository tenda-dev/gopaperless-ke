<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Operations\Analytics;

use OCA\Libresign\Service\AccountService;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Provides account counts using Nextcloud's aggregate APIs, without scanning
 * the full account base. Temporary public-workflow users are excluded.
 *
 * Lifetime counts include disabled accounts. Enabled counts exclude disabled
 * accounts. Administrators are included; guests are excluded when the Guests
 * app group exists.
 *
 * Nextcloud has no portable way to identify other system accounts, so those
 * accounts cannot be reliably excluded.
 */
class AccountCountsProvider {
	private const GUEST_GROUP = 'guest_app';

	public function __construct(
		private IUserManager $userManager,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * @return array{lifetime: ?int, enabled: ?int} null when the backend can't count
	 */
	public function counts(): array {
		$total = $this->userManager->countUsersTotal();
		$disabled = $this->userManager->countDisabledUsers();
		// Treat unavailable aggregate counts as unknown, not zero, to avoid
		// reporting misleading account totals.
		if (!is_int($total) || !is_int($disabled)) {
			return ['lifetime' => null, 'enabled' => null];
		}

		// Only the excluded groups are enumerated; the full account base is never
		// scanned. Deduplicate by UID because a user may belong to both groups.
		$excludedUids = [];
		$excludedDisabledUids = [];
		foreach ([AccountService::PUBLIC_WORKFLOW_GROUP, self::GUEST_GROUP] as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				continue;
			}
			foreach ($group->getUsers() as $user) {
				$uid = $user->getUID();
				$excludedUids[$uid] = true;
				if (!$user->isEnabled()) {
					$excludedDisabledUids[$uid] = true;
				}
			}
		}
		$excludedTotal = count($excludedUids);
		$excludedDisabled = count($excludedDisabledUids);

		return [
			'lifetime' => max(0, $total - $excludedTotal),
			'enabled' => max(0, ($total - $disabled) - ($excludedTotal - $excludedDisabled)),
		];
	}
}
