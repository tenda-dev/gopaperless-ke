<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\SignatureProfile;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\SignatureProfile\ValueObject\SignatureProfile;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Resolves the signature appearance profile for a requester from Nextcloud
 * group membership. Reads group membership and the configured profiles only;
 * it never touches the signing path. Nextcloud group membership is the single
 * source of truth, and only groups with a configured profile participate.
 */
class SignatureProfileService {
	private const CONFIG_KEY = 'appearance_profiles';

	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	public function getDefaultProfile(): SignatureProfile {
		return SignatureProfile::default();
	}

	/**
	 * Entry point used by the request workflow. When the customer appearance is
	 * not requested, the default profile is used; otherwise the requester's
	 * group profile is resolved.
	 */
	public function resolveForRequester(string $userId, bool $useGroupAppearance): SignatureProfile {
		if (!$useGroupAppearance) {
			return $this->getDefaultProfile();
		}
		return $this->getProfileForUser($userId);
	}

	/**
	 * Resolve the profile for a user from their Nextcloud groups.
	 *
	 * Falls back to the default profile when the user is unknown, has no groups,
	 * or belongs to no configured (profiled) group. When more than one profiled
	 * group matches, resolution is deterministic (lowest sorted group id) and the
	 * conflict is logged rather than silently picking an arbitrary group.
	 */
	public function getProfileForUser(string $userId): SignatureProfile {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return $this->getDefaultProfile();
		}

		$configured = $this->appConfig->getValueArray(Application::APP_ID, self::CONFIG_KEY, []);
		if ($configured === []) {
			return $this->getDefaultProfile();
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);
		$profiledGroups = array_values(array_intersect($userGroups, array_keys($configured)));
		if ($profiledGroups === []) {
			return $this->getDefaultProfile();
		}

		sort($profiledGroups);
		if (count($profiledGroups) > 1) {
			$this->logger->warning(
				'User belongs to multiple configured appearance-profile groups; resolving deterministically to the lowest sorted group id.',
				[
					'userId' => $userId,
					'conflictingGroups' => $profiledGroups,
					'resolvedGroup' => $profiledGroups[0],
				],
			);
		}

		$groupId = $profiledGroups[0];
		$raw = $configured[$groupId] ?? [];
		if (!is_array($raw)) {
			$raw = [];
		}
		return SignatureProfile::fromArray($raw);
	}
}
