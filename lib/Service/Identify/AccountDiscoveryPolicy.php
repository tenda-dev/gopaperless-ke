<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Identify;

use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Shared account-discovery and discoverability gate for the unified signer search.
 *
 * Centralises the Nextcloud full-match / group-only policy so that the account
 * resolution performed while rendering email identities (SignerIdentityReconciler)
 * applies exactly the same rules the EmailAccountPlugin applies when it emits
 * account signers. The two must stay in lock-step; AccountDiscoveryPolicyParityTest
 * pins them to the same decisions.
 *
 * Two independent lookup paths, never collapsed into one:
 *   - email path: IUserManager::getByEmail() (exact system-email match)
 *   - uid path:   IUserManager::get(strtolower()) (exact uid match; covers MIMI
 *                 accounts whose uid is the address while the email field is empty)
 *
 * Each path is gated by its own full-match setting, so a path disabled by its
 * setting never suppresses an account the other path legitimately permits. The
 * hard group-only restriction and the account-enabled state apply to every
 * candidate, including exact full matches, and are never bypassed.
 */
class AccountDiscoveryPolicy {
	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private IUserManager $userManager,
	) {
	}

	/**
	 * Merged, uid-keyed candidate set for an email/uid search, preserving the
	 * email-first / uid-second insertion order. Each entry carries per-path
	 * provenance and the final discoverable decision.
	 *
	 * @return array<string, array{user: IUser, viaEmail: bool, viaUid: bool, discoverable: bool}>
	 */
	public function resolveCandidates(string $search): array {
		$gates = $this->readGates();

		$candidates = [];
		foreach ($this->userManager->getByEmail($search) as $user) {
			$uid = $user->getUID();
			if (!isset($candidates[$uid])) {
				$candidates[$uid] = ['user' => $user, 'viaEmail' => false, 'viaUid' => false];
			}
			$candidates[$uid]['viaEmail'] = true;
		}
		$byUid = $this->userManager->get(strtolower($search));
		if ($byUid !== null) {
			$uid = $byUid->getUID();
			if (!isset($candidates[$uid])) {
				$candidates[$uid] = ['user' => $byUid, 'viaEmail' => false, 'viaUid' => false];
			}
			$candidates[$uid]['viaUid'] = true;
		}

		$resolved = [];
		foreach ($candidates as $uid => $candidate) {
			$resolved[$uid] = [
				'user' => $candidate['user'],
				'viaEmail' => $candidate['viaEmail'],
				'viaUid' => $candidate['viaUid'],
				'discoverable' => $this->isDiscoverable(
					$candidate['user'],
					$candidate['viaEmail'],
					$candidate['viaUid'],
					$gates,
				),
			];
		}
		return $resolved;
	}

	/**
	 * Independent per-path resolution for a single email identity. Email and UID
	 * are resolved separately and never collapsed.
	 *
	 * The caller keys "suppress vs keep" on account existence (account !== null)
	 * and the representation (account vs suppress/preserve) on discoverability.
	 *
	 * @return array{
	 *   email: array{account: ?IUser, discoverable: bool},
	 *   uid: array{account: ?IUser, discoverable: bool}
	 * }
	 */
	public function resolve(string $identifier): array {
		$gates = $this->readGates();

		$emailAccount = null;
		$byEmail = $this->userManager->getByEmail($identifier);
		if (!empty($byEmail)) {
			$emailAccount = $byEmail[0];
		}
		$uidAccount = $this->userManager->get(strtolower($identifier));

		return [
			'email' => [
				'account' => $emailAccount,
				'discoverable' => $emailAccount !== null
					&& $this->isDiscoverable($emailAccount, true, false, $gates),
			],
			'uid' => [
				'account' => $uidAccount,
				'discoverable' => $uidAccount !== null
					&& $this->isDiscoverable($uidAccount, false, true, $gates),
			],
		];
	}

	/**
	 * @param array{fullMatch: bool, fullMatchEmail: bool, fullMatchUserId: bool, groupOnly: bool, allowedGroups: list<string>} $gates
	 */
	private function isDiscoverable(IUser $user, bool $viaEmail, bool $viaUid, array $gates): bool {
		if (!$user->isEnabled()) {
			return false;
		}
		// Email/UID -> account is a full-match capability in Nextcloud core
		// (OC\Collaboration\Collaborators\UserPlugin, stable34): it is reached
		// through the full-match block only, never through broad enumeration.
		if (!$gates['fullMatch']) {
			return false;
		}
		// Permitted when at least one lookup path that found this account is
		// allowed by its own full-match setting.
		$permitted = ($viaEmail && $gates['fullMatchEmail'])
			|| ($viaUid && $gates['fullMatchUserId']);
		if (!$permitted) {
			return false;
		}
		// Hard group-only restriction (not an enumeration restriction): applies to
		// every candidate, including exact full matches, and is never bypassed.
		if ($gates['groupOnly']) {
			$userGroups = $this->groupManager->getUserGroupIds($user);
			if (array_intersect($gates['allowedGroups'], $userGroups) === []) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Read the discovery gates once per resolution. allowedGroups is the current
	 * requester's groups minus the configured exclude list; empty when there is
	 * no current user (in which case no candidate can clear a group-only gate).
	 *
	 * @return array{fullMatch: bool, fullMatchEmail: bool, fullMatchUserId: bool, groupOnly: bool, allowedGroups: list<string>}
	 */
	private function readGates(): array {
		$fullMatch = $this->appConfig->getValueString('core', 'shareapi_restrict_user_enumeration_full_match', 'yes') === 'yes';
		$fullMatchEmail = $this->appConfig->getValueString('core', 'shareapi_restrict_user_enumeration_full_match_email', 'yes') === 'yes';
		$fullMatchUserId = $this->appConfig->getValueString('core', 'shareapi_restrict_user_enumeration_full_match_user_id', 'yes') === 'yes';
		$groupOnly = $this->appConfig->getValueString('core', 'shareapi_only_share_with_group_members', 'no') === 'yes';
		$excludeGroups = json_decode(
			$this->appConfig->getValueString('core', 'shareapi_only_share_with_group_members_exclude_group_list', '[]'),
			true,
			512,
			JSON_THROW_ON_ERROR
		) ?? [];

		$currentUser = $this->userSession->getUser();
		$allowedGroups = $currentUser !== null
			? array_values(array_diff($this->groupManager->getUserGroupIds($currentUser), $excludeGroups))
			: [];

		return [
			'fullMatch' => $fullMatch,
			'fullMatchEmail' => $fullMatchEmail,
			'fullMatchUserId' => $fullMatchUserId,
			'groupOnly' => $groupOnly,
			'allowedGroups' => $allowedGroups,
		];
	}
}
