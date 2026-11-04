<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Collaboration\Collaborators;

use OCA\Libresign\Service\Identify\SignerSearchContext;
use OCP\Collaboration\Collaborators\ISearchPlugin;
use OCP\Collaboration\Collaborators\ISearchResult;
use OCP\Collaboration\Collaborators\SearchResultType;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Resolves email searches to existing internal accounts.
 *
 * When a requester types an email address in the unified/email tab, this plugin
 * returns account signers for every account that has that email configured,
 * or that has the address as its uid.
 * External/unknown email addresses continue to be handled by the core email
 * plugin as regular email signers.
 */
class EmailAccountPlugin implements ISearchPlugin {
	public const TYPE_SIGNER_EMAIL_ACCOUNT = 54;

	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private IUserManager $userManager,
		private SignerSearchContext $searchContext,
		private LoggerInterface $logger,
	) {
	}

	private function isIdentifyMethodEnabled(string $name): bool {
		$methods = $this->appConfig->getValueArray('libresign', 'identify_methods', []);
		foreach ($methods as $method) {
			if (($method['name'] ?? '') === $name) {
				return (bool)($method['enabled'] ?? false);
			}
		}
		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	#[\Override]
	public function search($search, $limit, $offset, ISearchResult $searchResult): bool {
		$method = $this->searchContext->getMethod();

		if ($method !== 'all' && $method !== 'email') {
			return false;
		}

		if (!$this->isIdentifyMethodEnabled('email')) {
			return false;
		}

		$search = trim((string)$search);
		if ($search === '' || !str_contains($search, '@')) {
			return false;
		}

		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return false;
		}

		// Email/UID -> account is a full-match capability in Nextcloud core
		// (OC\Collaboration\Collaborators\UserPlugin, stable34): it is reached
		// through the full-match block only, never through broad enumeration, so
		// these lookups must not become an alternative route around full-match.
		$fullMatch = $this->appConfig->getValueString('core', 'shareapi_restrict_user_enumeration_full_match', 'yes') === 'yes';
		if (!$fullMatch) {
			return false;
		}
		// Path-specific full-match controls, enforced independently per lookup.
		$fullMatchEmail = $this->appConfig->getValueString('core', 'shareapi_restrict_user_enumeration_full_match_email', 'yes') === 'yes';
		$fullMatchUserId = $this->appConfig->getValueString('core', 'shareapi_restrict_user_enumeration_full_match_user_id', 'yes') === 'yes';

		// Hard group-only restriction (not an enumeration restriction): applies to
		// every candidate, including exact full matches, and is never bypassed.
		$shareWithGroupOnly = $this->appConfig->getValueString('core', 'shareapi_only_share_with_group_members', 'no') === 'yes';
		$shareWithGroupOnlyExcludeGroupsList = json_decode(
			$this->appConfig->getValueString('core', 'shareapi_only_share_with_group_members_exclude_group_list', '[]'),
			true,
			512,
			JSON_THROW_ON_ERROR
		) ?? [];
		$allowedGroups = array_diff($this->groupManager->getUserGroupIds($currentUser), $shareWithGroupOnlyExcludeGroupsList);

		// Two independent exact lookups, kept separate so each full-match setting is
		// enforced on its own path. getByEmail() is an exact primary-email match;
		// get() is an exact UID match (covers MIMI accounts whose uid is the address
		// while the email field is empty). Provenance is tracked so an account found
		// by both paths is emitted once, and a path disabled by its setting never
		// suppresses an account the other path legitimately permits.
		$candidates = [];
		foreach ($this->userManager->getByEmail($search) as $user) {
			$uid = $user->getUID();
			if (!isset($candidates[$uid])) {
				$candidates[$uid] = ['user' => $user, 'email' => false, 'uid' => false];
			}
			$candidates[$uid]['email'] = true;
		}
		$byUid = $this->userManager->get(strtolower($search));
		if ($byUid !== null) {
			$uid = $byUid->getUID();
			if (!isset($candidates[$uid])) {
				$candidates[$uid] = ['user' => $byUid, 'email' => false, 'uid' => false];
			}
			$candidates[$uid]['uid'] = true;
		}

		$items = [];
		foreach ($candidates as $candidate) {
			$user = $candidate['user'];
			if (!$user->isEnabled()) {
				continue;
			}

			// Permitted when at least one lookup path that found this account is
			// allowed by its own full-match setting.
			$permitted = ($candidate['email'] && $fullMatchEmail)
				|| ($candidate['uid'] && $fullMatchUserId);
			if (!$permitted) {
				continue;
			}

			$userId = $user->getUID();
			$userGroups = $this->groupManager->getUserGroupIds($user);
			$inAllowedGroup = array_intersect($allowedGroups, $userGroups) !== [];
			if ($shareWithGroupOnly && !$inAllowedGroup) {
				continue;
			}

			$displayName = $user->getDisplayName() !== '' ? $user->getDisplayName() : $userId;

			$items[] = [
				'label' => $displayName,
				'shareWithDisplayNameUnique' => $search,
				'method' => 'account',
				'value' => [
					'shareType' => self::TYPE_SIGNER_EMAIL_ACCOUNT,
					'shareWith' => $userId,
				],
			];
		}

		$hasMore = count($items) > ($offset + $limit);
		$pagedItems = array_slice($items, $offset, $limit);

		$result = ['wide' => [], 'exact' => []];
		$searchLower = strtolower($search);
		foreach ($pagedItems as $item) {
			if (strtolower($item['shareWithDisplayNameUnique']) === $searchLower
				|| strtolower($item['label']) === $searchLower
			) {
				$result['exact'][] = $item;
			} else {
				$result['wide'][] = $item;
			}
		}

		$type = new SearchResultType('email-account');
		$searchResult->addResultSet($type, $result['wide'], $result['exact']);

		return $hasMore;
	}
}
