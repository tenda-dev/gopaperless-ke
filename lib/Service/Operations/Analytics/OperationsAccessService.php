<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Operations\Analytics;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Exception\LibresignException;
use OCP\AppFramework\Http;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * Single authorization gate for the Operations Analytics surface.
 *
 * Access = feature flag enabled AND (Nextcloud admin OR member of any group
 * listed in `operations_groups`). Every operations endpoint must go through
 * assertAccess(); the frontend capability flag is visibility only.
 *
 * Fails closed: a missing/mistyped flag reads as disabled, and a mistyped
 * group list reads as the default (admins only).
 *
 * The `operations` group is created lazily the first time an authorization
 * check runs with the feature enabled (see ensureOperationsGroup()). Nothing
 * is added to it and `operations_groups` is never modified.
 */
class OperationsAccessService {
	public const CONFIG_ENABLED = 'operations_dashboard_enabled';
	public const CONFIG_GROUPS = 'operations_groups';

	/** Nextcloud group created on demand so it never has to be set up by hand. */
	public const OPERATIONS_GROUP_NAME = 'operations';

	/** @var list<string> */
	public const DEFAULT_GROUPS = ['admin'];

	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
	) {
	}

	private bool $groupEnsured = false;

	public function isEnabled(): bool {
		try {
			return $this->appConfig->getValueBool(Application::APP_ID, self::CONFIG_ENABLED, false);
		} catch (AppConfigTypeConflictException) {
			return false;
		}
	}

	/**
	 * @throws LibresignException 403 when the feature is disabled
	 */
	public function assertEnabled(): void {
		if (!$this->isEnabled()) {
			throw $this->forbidden();
		}
	}

	/**
	 * Groups whose members may use the dashboard (admins always may).
	 *
	 * @return list<string>
	 */
	public function getOperationsGroups(): array {
		try {
			$groups = $this->appConfig->getValueArray(Application::APP_ID, self::CONFIG_GROUPS, self::DEFAULT_GROUPS);
		} catch (AppConfigTypeConflictException) {
			return self::DEFAULT_GROUPS;
		}
		return array_values(array_filter(
			array_map(static fn (mixed $group): string => (string)$group, $groups),
			static fn (string $group): bool => $group !== '',
		));
	}

	/**
	 * Creates the `operations` group when it does not exist. Best effort: a
	 * failure must never turn an authorization check into an error, and it is
	 * attempted at most once per instance (one request).
	 */
	public function ensureOperationsGroup(): void {
		if ($this->groupEnsured) {
			return;
		}
		$this->groupEnsured = true;
		try {
			if (!$this->groupManager->groupExists(self::OPERATIONS_GROUP_NAME)) {
				$this->groupManager->createGroup(self::OPERATIONS_GROUP_NAME);
			}
		} catch (\Throwable) {
			// Intentionally silent: access decisions do not depend on the group existing.
		}
	}

	public function isAuthorized(?IUser $user): bool {
		if ($user === null || $user->getUID() === '') {
			return false;
		}
		$this->ensureOperationsGroup();
		if ($this->groupManager->isAdmin($user->getUID())) {
			return true;
		}
		$configured = $this->getOperationsGroups();
		if ($configured === []) {
			return false;
		}
		return array_intersect($this->groupManager->getUserGroupIds($user), $configured) !== [];
	}

	/**
	 * Non-throwing form for visibility decisions (bootstrap flag).
	 * Never a substitute for assertAccess() on data endpoints.
	 */
	public function canAccess(?IUser $user): bool {
		return $this->isEnabled() && $this->isAuthorized($user);
	}

	/**
	 * @throws LibresignException 403 when disabled or the user is not authorized
	 *                            (deliberately indistinguishable)
	 */
	public function assertAccess(?IUser $user): void {
		$this->assertEnabled();
		if (!$this->isAuthorized($user)) {
			throw $this->forbidden();
		}
	}

	private function forbidden(): LibresignException {
		return new LibresignException('Forbidden', Http::STATUS_FORBIDDEN);
	}
}
