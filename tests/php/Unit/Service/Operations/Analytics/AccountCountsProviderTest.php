<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Operations\Analytics;

use OCA\Libresign\Service\AccountService;
use OCA\Libresign\Service\Operations\Analytics\AccountCountsProvider;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;

final class AccountCountsProviderTest extends TestCase {
	private const GUEST_GROUP = 'guest_app';

	private IUserManager&MockObject $userManager;
	private IGroupManager&MockObject $groupManager;
	private AccountCountsProvider $provider;

	public function setUp(): void {
		parent::setUp();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->provider = new AccountCountsProvider($this->userManager, $this->groupManager);
	}

	private function user(string $uid, bool $enabled): IUser {
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		$u->method('isEnabled')->willReturn($enabled);
		return $u;
	}

	/** @param list<IUser> $users */
	private function group(array $users): IGroup {
		$g = $this->createMock(IGroup::class);
		$g->method('getUsers')->willReturn($users);
		return $g;
	}

	/**
	 * @param array<string, ?IGroup> $map group id => group|null
	 */
	private function groups(array $map): void {
		$this->groupManager->method('get')->willReturnCallback(
			static fn (string $id): ?IGroup => $map[$id] ?? null,
		);
	}

	public function testDisabledAccountsCountInLifetimeButNotEnabled(): void {
		$this->userManager->method('countUsersTotal')->willReturn(100);
		$this->userManager->method('countDisabledUsers')->willReturn(7);
		$this->groups([]); // no excluded groups present

		// Admins are ordinary members here and are never excluded.
		$this->assertSame(['lifetime' => 100, 'enabled' => 93], $this->provider->counts());
	}

	public function testTemporaryPublicWorkflowUsersAreExcludedFromBoth(): void {
		$this->userManager->method('countUsersTotal')->willReturn(100);
		$this->userManager->method('countDisabledUsers')->willReturn(7);
		$members = [];
		for ($i = 0; $i < 18; $i++) {
			$members[] = $this->user("pw-$i", true);
		}
		$members[] = $this->user('pw-d1', false);
		$members[] = $this->user('pw-d2', false); // 20 total, 2 disabled
		$this->groups([AccountService::PUBLIC_WORKFLOW_GROUP => $this->group($members)]);

		// lifetime 100-20=80; enabled (100-7) - (20-2) = 75
		$this->assertSame(['lifetime' => 80, 'enabled' => 75], $this->provider->counts());
	}

	public function testGuestsAreExcludedWhenGroupPresent(): void {
		$this->userManager->method('countUsersTotal')->willReturn(100);
		$this->userManager->method('countDisabledUsers')->willReturn(7);
		$guests = [$this->user('g1', true), $this->user('g2', true), $this->user('g3', false)]; // 3 total, 1 disabled
		$this->groups([self::GUEST_GROUP => $this->group($guests)]);

		// lifetime 100-3=97; enabled (93) - (3-1) = 91
		$this->assertSame(['lifetime' => 97, 'enabled' => 91], $this->provider->counts());
	}

	public function testOverlappingExcludedMemberIsSubtractedOnce(): void {
		$this->userManager->method('countUsersTotal')->willReturn(100);
		$this->userManager->method('countDisabledUsers')->willReturn(7);
		$shared = $this->user('shared', false); // disabled, in BOTH groups
		$this->groups([
			AccountService::PUBLIC_WORKFLOW_GROUP => $this->group([$this->user('pw1', true), $shared]),
			self::GUEST_GROUP => $this->group([$shared, $this->user('g1', true)]),
		]);

		// Unique excluded = {pw1, shared, g1} = 3; unique disabled = {shared} = 1.
		// lifetime 100-3=97; enabled (93) - (3-1) = 91
		$this->assertSame(['lifetime' => 97, 'enabled' => 91], $this->provider->counts());
	}

	public function testGuestGroupAbsentIsHandledNormally(): void {
		$this->userManager->method('countUsersTotal')->willReturn(50);
		$this->userManager->method('countDisabledUsers')->willReturn(0);
		$this->groups([AccountService::PUBLIC_WORKFLOW_GROUP => $this->group([$this->user('pw1', true)])]);

		$this->assertSame(['lifetime' => 49, 'enabled' => 49], $this->provider->counts());
	}

	public function testFailedTotalCountYieldsNulls(): void {
		$this->userManager->method('countUsersTotal')->willReturn(false);
		$this->userManager->method('countDisabledUsers')->willReturn(0);

		$this->assertSame(['lifetime' => null, 'enabled' => null], $this->provider->counts());
	}

	public function testFailedDisabledCountYieldsNulls(): void {
		$this->userManager->method('countUsersTotal')->willReturn(100);
		$this->userManager->method('countDisabledUsers')->willReturn(false);

		$this->assertSame(['lifetime' => null, 'enabled' => null], $this->provider->counts());
	}

	public function testNeverNegative(): void {
		$this->userManager->method('countUsersTotal')->willReturn(5);
		$this->userManager->method('countDisabledUsers')->willReturn(0);
		$many = [];
		for ($i = 0; $i < 9; $i++) {
			$many[] = $this->user("pw-$i", true);
		}
		$this->groups([AccountService::PUBLIC_WORKFLOW_GROUP => $this->group($many)]);

		$this->assertSame(['lifetime' => 0, 'enabled' => 0], $this->provider->counts());
	}
}
