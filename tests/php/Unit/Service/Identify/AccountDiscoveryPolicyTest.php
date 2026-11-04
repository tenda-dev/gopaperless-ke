<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Identify;

use OCA\Libresign\Service\Identify\AccountDiscoveryPolicy;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class AccountDiscoveryPolicyTest extends TestCase {
	private const DEFAULTS = [
		'shareapi_restrict_user_enumeration_full_match' => 'yes',
		'shareapi_restrict_user_enumeration_full_match_email' => 'yes',
		'shareapi_restrict_user_enumeration_full_match_user_id' => 'yes',
		'shareapi_only_share_with_group_members' => 'no',
		'shareapi_only_share_with_group_members_exclude_group_list' => '[]',
	];

	private function user(string $uid, bool $enabled = true): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}

	private function appConfig(array $overrides = []): IAppConfig {
		$cfg = array_merge(self::DEFAULTS, $overrides);
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $cfg[$key] ?? $default
		);
		return $appConfig;
	}

	/**
	 * @param array<string, IUser[]> $byEmail
	 * @param array<string, IUser> $byUid
	 * @param array<string, string[]> $groups
	 */
	private function policy(array $byEmail, array $byUid, array $groups, string $requesterUid = 'requester'): AccountDiscoveryPolicy {
		return $this->policyWithConfig($this->appConfig(), $byEmail, $byUid, $groups, $requesterUid);
	}

	private function policyWithConfig(IAppConfig $appConfig, array $byEmail, array $byUid, array $groups, string $requesterUid = 'requester'): AccountDiscoveryPolicy {
		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('getByEmail')->willReturnCallback(static fn (string $e): array => $byEmail[$e] ?? []);
		$userManager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $byUid[$uid] ?? null);

		$groupManager = $this->createStub(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturnCallback(static fn (IUser $u): array => $groups[$u->getUID()] ?? []);

		$requester = $this->user($requesterUid);
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($requester);

		return new AccountDiscoveryPolicy($appConfig, $groupManager, $session, $userManager);
	}

	public function testResolveDiscoverableAccountViaEmail(): void {
		$alice = $this->user('a');
		$policy = $this->policy(['a@x' => [$alice]], [], ['requester' => ['g1'], 'a' => ['g1']]);
		$res = $policy->resolve('a@x');
		$this->assertNotNull($res['email']['account']);
		$this->assertTrue($res['email']['discoverable']);
		$this->assertNull($res['uid']['account']);
		$this->assertFalse($res['uid']['discoverable']);
	}

	public function testResolveExistingButGroupOnlyNonDiscoverable(): void {
		$erick = $this->user('e');
		$policy = $this->policy(
			['e@x' => [$erick]],
			[],
			['requester' => ['g1'], 'e' => []],
			'requester',
		);
		$policy = $this->policyWithConfig(
			$this->appConfig(['shareapi_only_share_with_group_members' => 'yes']),
			['e@x' => [$erick]],
			[],
			['requester' => ['g1'], 'e' => []],
		);
		$res = $policy->resolve('e@x');
		$this->assertNotNull($res['email']['account'], 'account exists');
		$this->assertFalse($res['email']['discoverable'], 'but is not discoverable');
	}

	public function testIndependentPathsEmailDisabledUidStillResolves(): void {
		$uidOnly = $this->user('uidonly@x');
		$policy = $this->policyWithConfig(
			$this->appConfig(['shareapi_restrict_user_enumeration_full_match_email' => 'no']),
			[],
			['uidonly@x' => $uidOnly],
			['requester' => ['g1'], 'uidonly@x' => ['g1']],
		);
		$res = $policy->resolve('uidonly@x');
		$this->assertFalse($res['email']['discoverable']);
		$this->assertTrue($res['uid']['discoverable']);
	}

	public function testIndependentPathsUidDisabledEmailStillResolves(): void {
		$alice = $this->user('a');
		$policy = $this->policyWithConfig(
			$this->appConfig(['shareapi_restrict_user_enumeration_full_match_user_id' => 'no']),
			['a@x' => [$alice]],
			['a@x' => $alice],
			['requester' => ['g1'], 'a' => ['g1']],
		);
		$res = $policy->resolve('a@x');
		$this->assertTrue($res['email']['discoverable']);
		$this->assertFalse($res['uid']['discoverable']);
	}

	public function testFullMatchMasterDisabledSuppressesEverything(): void {
		$alice = $this->user('a');
		$policy = $this->policyWithConfig(
			$this->appConfig(['shareapi_restrict_user_enumeration_full_match' => 'no']),
			['a@x' => [$alice]],
			['a@x' => $alice],
			['requester' => ['g1'], 'a' => ['g1']],
		);
		$res = $policy->resolve('a@x');
		$this->assertFalse($res['email']['discoverable']);
		$this->assertFalse($res['uid']['discoverable']);
		$this->assertNotNull($res['email']['account'], 'existence is independent of discoverability');
	}

	public function testDisabledUserNeverDiscoverable(): void {
		$disabled = $this->user('d', false);
		$policy = $this->policy(['d@x' => [$disabled]], [], ['requester' => ['g1'], 'd' => ['g1']]);
		$res = $policy->resolve('d@x');
		$this->assertFalse($res['email']['discoverable']);
	}

	public function testExcludeGroupMakesAccountNonDiscoverable(): void {
		$excluded = $this->user('x');
		$policy = $this->policyWithConfig(
			$this->appConfig([
				'shareapi_only_share_with_group_members' => 'yes',
				'shareapi_only_share_with_group_members_exclude_group_list' => '["shared"]',
			]),
			['x@x' => [$excluded]],
			[],
			['requester' => ['shared'], 'x' => ['shared']],
		);
		$res = $policy->resolve('x@x');
		// Only shared group is excluded -> requester has no effective allowed group.
		$this->assertFalse($res['email']['discoverable']);
	}

	public function testResolveCandidatesMergesEmailAndUidPreservingOrder(): void {
		$alice = $this->user('a');
		$bob = $this->user('b@x');
		$policy = $this->policy(
			['b@x' => [$alice]],
			['b@x' => $bob],
			['requester' => ['g1'], 'a' => ['g1'], 'b@x' => ['g1']],
		);
		$candidates = $policy->resolveCandidates('b@x');
		$this->assertSame(['a', 'b@x'], array_keys($candidates));
		$this->assertTrue($candidates['a']['viaEmail']);
		$this->assertFalse($candidates['a']['viaUid']);
		$this->assertTrue($candidates['b@x']['viaUid']);
		$this->assertTrue($candidates['a']['discoverable']);
		$this->assertTrue($candidates['b@x']['discoverable']);
	}
}
