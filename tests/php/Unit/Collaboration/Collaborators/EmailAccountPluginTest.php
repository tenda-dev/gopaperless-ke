<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Collaboration\Collaborators;

use OC\Collaboration\Collaborators\SearchResult;
use OCA\Libresign\Collaboration\Collaborators\EmailAccountPlugin;
use OCA\Libresign\Service\Identify\SignerSearchContext;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;

class EmailAccountPluginTest extends TestCase {
	private array $userGroups = [];

	#[DataProvider('providerSearchScenarios')]
	public function testSearchRespectsEnumerationRules(
		string $method,
		array $config,
		bool $enabled,
		array $currentGroups,
		array $targetGroups,
		bool $userEnabled,
		int $expectedCount,
	): void {
		$appConfig = $this->applyAppConfig($config);
		if ($enabled) {
			$appConfig->setValueArray('libresign', 'identify_methods', [
				['name' => 'email', 'enabled' => true],
			]);
		}

		$currentUser = $this->createStub(IUser::class);
		$currentUser->method('getUID')->willReturn('current');

		$userSession = $this->createStub(IUserSession::class);
		$userSession->method('getUser')->willReturn($currentUser);

		$targetUser = $this->createStub(IUser::class);
		$targetUser->method('getUID')->willReturn('target');
		$targetUser->method('isEnabled')->willReturn($userEnabled);
		$targetUser->method('getDisplayName')->willReturn('Target User');

		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('getByEmail')
			->with('signer@example.com')
			->willReturn($userEnabled ? [$targetUser] : []);

		$groupManager = $this->createStub(IGroupManager::class);
		$groupManager->method('getUserGroupIds')
			->willReturnCallback(function ($subject) use ($currentUser, $targetUser, $currentGroups, $targetGroups): array {
				if ($subject === $currentUser) {
					return $currentGroups;
				}
				if ($subject === $targetUser) {
					return $targetGroups;
				}
				return [];
			});

		$context = new SignerSearchContext();
		$context->set($method, 'signer@example.com', 'signer@example.com');

		$plugin = new EmailAccountPlugin(
			$appConfig,
			$groupManager,
			$userSession,
			$userManager,
			$context,
			$this->createStub(\Psr\Log\LoggerInterface::class),
		);

		$searchResult = new SearchResult();
		$plugin->search('signer@example.com', 10, 0, $searchResult);

		$results = $searchResult->asArray();
		$items = array_merge($results['email-account'] ?? [], $results['exact']['email-account'] ?? []);
		$this->assertCount($expectedCount, $items);
		if ($expectedCount > 0) {
			$this->assertSame(EmailAccountPlugin::TYPE_SIGNER_EMAIL_ACCOUNT, $items[0]['value']['shareType']);
			$this->assertSame('account', $items[0]['method']);
		}
	}

	public static function providerSearchScenarios(): array {
		return [
			'email disabled' => [
				'method' => 'email',
				'config' => ['shareapi_allow_share_dialog_user_enumeration' => 'yes'],
				'enabled' => false,
				'currentGroups' => ['sales'],
				'targetGroups' => ['sales'],
				'userEnabled' => true,
				'expectedCount' => 0,
			],
			'email enabled returns account signer' => [
				'method' => 'email',
				'config' => ['shareapi_allow_share_dialog_user_enumeration' => 'yes'],
				'enabled' => true,
				'currentGroups' => ['sales'],
				'targetGroups' => ['engineering'],
				'userEnabled' => true,
				'expectedCount' => 1,
			],
			// Native parity: full-match (exact) resolution ignores the enumeration
			// "restrict to group" setting; only the hard group-only restriction applies.
			'to_group does not restrict exact full match' => [
				'method' => 'email',
				'config' => [
					'shareapi_allow_share_dialog_user_enumeration' => 'yes',
					'shareapi_restrict_user_enumeration_to_group' => 'yes',
				],
				'enabled' => true,
				'currentGroups' => ['sales'],
				'targetGroups' => ['engineering'],
				'userEnabled' => true,
				'expectedCount' => 1,
			],
			'share with group only without common group' => [
				'method' => 'email',
				'config' => [
					'shareapi_allow_share_dialog_user_enumeration' => 'yes',
					'shareapi_only_share_with_group_members' => 'yes',
				],
				'enabled' => true,
				'currentGroups' => ['sales'],
				'targetGroups' => ['engineering'],
				'userEnabled' => true,
				'expectedCount' => 0,
			],
			'disabled user filtered' => [
				'method' => 'email',
				'config' => ['shareapi_allow_share_dialog_user_enumeration' => 'yes'],
				'enabled' => true,
				'currentGroups' => ['sales'],
				'targetGroups' => ['sales'],
				'userEnabled' => false,
				'expectedCount' => 0,
			],
			'non email method ignored' => [
				'method' => 'sms',
				'config' => ['shareapi_allow_share_dialog_user_enumeration' => 'yes'],
				'enabled' => true,
				'currentGroups' => ['sales'],
				'targetGroups' => ['sales'],
				'userEnabled' => true,
				'expectedCount' => 0,
			],
			'unified search resolves email to account' => [
				'method' => 'all',
				'config' => ['shareapi_allow_share_dialog_user_enumeration' => 'yes'],
				'enabled' => true,
				'currentGroups' => ['sales'],
				'targetGroups' => ['sales'],
				'userEnabled' => true,
				'expectedCount' => 1,
			],
		];
	}

	#[DataProvider('providerUidIsTheEmail')]
	public function testAnAccountWhoseUidIsTheEmailIsFoundOnce(bool $byEmail): void {
		$appConfig = $this->applyAppConfig(['shareapi_allow_share_dialog_user_enumeration' => 'yes']);
		$appConfig->setValueArray('libresign', 'identify_methods', [
			['name' => 'email', 'enabled' => true],
		]);

		$currentUser = $this->createStub(IUser::class);
		$currentUser->method('getUID')->willReturn('current');
		$userSession = $this->createStub(IUserSession::class);
		$userSession->method('getUser')->willReturn($currentUser);

		$mimiUser = $this->createStub(IUser::class);
		$mimiUser->method('getUID')->willReturn('signer@example.com');
		$mimiUser->method('isEnabled')->willReturn(true);
		$mimiUser->method('getDisplayName')->willReturn('Signer');

		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('getByEmail')->willReturn($byEmail ? [$mimiUser] : []);
		$userManager->method('get')->willReturnMap([['signer@example.com', $mimiUser]]);

		$context = new SignerSearchContext();
		$context->set('email', 'Signer@example.com', 'Signer@example.com');

		$plugin = new EmailAccountPlugin(
			$appConfig,
			$this->createStub(IGroupManager::class),
			$userSession,
			$userManager,
			$context,
			$this->createStub(\Psr\Log\LoggerInterface::class),
		);

		$searchResult = new SearchResult();
		$plugin->search('Signer@example.com', 10, 0, $searchResult);

		$results = $searchResult->asArray();
		$items = array_merge($results['email-account'] ?? [], $results['exact']['email-account'] ?? []);
		$this->assertCount(1, $items);
		$this->assertSame('signer@example.com', $items[0]['value']['shareWith']);
	}

	public static function providerUidIsTheEmail(): array {
		return [
			'email field empty' => ['byEmail' => false],
			'email field set too' => ['byEmail' => true],
		];
	}

	public function testExactPrimaryEmailResolvesWhenPermitted(): void {
		$appConfig = $this->applyAppConfig([
			'shareapi_restrict_user_enumeration_full_match' => 'yes',
			'shareapi_restrict_user_enumeration_full_match_email' => 'yes',
		]);
		$appConfig->setValueArray('libresign', 'identify_methods', [['name' => 'email', 'enabled' => true]]);

		$items = $this->runSearch($appConfig, 'signer@example.com', 'email', [
			'getByEmail' => [$this->enabledUser('target', 'Target')],
		]);
		$this->assertCount(1, $items);
		$this->assertSame('account', $items[0]['method']);
		$this->assertSame('target', $items[0]['value']['shareWith']);
	}

	public function testFullMatchEmailDisabledSuppressesEmailAccount(): void {
		$appConfig = $this->applyAppConfig([
			'shareapi_restrict_user_enumeration_full_match' => 'yes',
			'shareapi_restrict_user_enumeration_full_match_email' => 'no',
		]);
		$appConfig->setValueArray('libresign', 'identify_methods', [['name' => 'email', 'enabled' => true]]);

		$items = $this->runSearch($appConfig, 'signer@example.com', 'email', [
			'getByEmail' => [$this->enabledUser('target', 'Target')],
		]);
		$this->assertCount(0, $items);
	}

	public function testFullMatchUserIdDisabledSuppressesUidAccount(): void {
		$appConfig = $this->applyAppConfig([
			'shareapi_restrict_user_enumeration_full_match' => 'yes',
			'shareapi_restrict_user_enumeration_full_match_user_id' => 'no',
		]);
		$appConfig->setValueArray('libresign', 'identify_methods', [['name' => 'email', 'enabled' => true]]);

		$items = $this->runSearch($appConfig, 'user@gmail', 'email', [
			'get' => ['user@gmail' => $this->enabledUser('user@gmail', 'Uid User')],
		]);
		$this->assertCount(0, $items);
	}

	public function testGlobalFullMatchDisabledSuppressesBothPaths(): void {
		$appConfig = $this->applyAppConfig([
			'shareapi_allow_share_dialog_user_enumeration' => 'yes',
			'shareapi_restrict_user_enumeration_full_match' => 'no',
		]);
		$appConfig->setValueArray('libresign', 'identify_methods', [['name' => 'email', 'enabled' => true]]);

		$items = $this->runSearch($appConfig, 'signer@example.com', 'email', [
			'getByEmail' => [$this->enabledUser('target', 'Target')],
			'get' => ['signer@example.com' => $this->enabledUser('signer@example.com', 'Mimi')],
		]);
		$this->assertCount(0, $items);
	}

	public function testEmailAndUidPathsResolveDifferentAccountsIndependently(): void {
		$appConfig = $this->applyAppConfig([
			'shareapi_restrict_user_enumeration_full_match' => 'yes',
			'shareapi_restrict_user_enumeration_full_match_email' => 'yes',
			'shareapi_restrict_user_enumeration_full_match_user_id' => 'yes',
		]);
		$appConfig->setValueArray('libresign', 'identify_methods', [['name' => 'email', 'enabled' => true]]);

		$items = $this->runSearch($appConfig, 'shared@example.com', 'email', [
			'getByEmail' => [$this->enabledUser('account-a', 'Account A')],
			'get' => ['shared@example.com' => $this->enabledUser('shared@example.com', 'Account B')],
		]);
		$shareWiths = array_map(static fn (array $i): string => $i['value']['shareWith'], $items);
		sort($shareWiths);
		$this->assertSame(['account-a', 'shared@example.com'], $shareWiths);
	}

	public function testUidPathSurvivesWhenEmailFullMatchDisabled(): void {
		$appConfig = $this->applyAppConfig([
			'shareapi_restrict_user_enumeration_full_match' => 'yes',
			'shareapi_restrict_user_enumeration_full_match_email' => 'no',
			'shareapi_restrict_user_enumeration_full_match_user_id' => 'yes',
		]);
		$appConfig->setValueArray('libresign', 'identify_methods', [['name' => 'email', 'enabled' => true]]);

		$items = $this->runSearch($appConfig, 'shared@example.com', 'email', [
			'getByEmail' => [$this->enabledUser('account-a', 'Account A')],
			'get' => ['shared@example.com' => $this->enabledUser('shared@example.com', 'Account B')],
		]);
		$shareWiths = array_map(static fn (array $i): string => $i['value']['shareWith'], $items);
		$this->assertSame(['shared@example.com'], $shareWiths);
	}

	public function testSameAccountFromBothPathsEmittedOnce(): void {
		$appConfig = $this->applyAppConfig(['shareapi_restrict_user_enumeration_full_match' => 'yes']);
		$appConfig->setValueArray('libresign', 'identify_methods', [['name' => 'email', 'enabled' => true]]);

		$user = $this->enabledUser('signer@example.com', 'Signer');
		$items = $this->runSearch($appConfig, 'signer@example.com', 'email', [
			'getByEmail' => [$user],
			'get' => ['signer@example.com' => $user],
		]);
		$this->assertCount(1, $items);
		$this->assertSame('signer@example.com', $items[0]['value']['shareWith']);
	}

	public function testExactUidThatIsNotAValidEmailResolvesViaUidPath(): void {
		$appConfig = $this->applyAppConfig([
			'shareapi_restrict_user_enumeration_full_match' => 'yes',
			'shareapi_restrict_user_enumeration_full_match_user_id' => 'yes',
		]);
		$appConfig->setValueArray('libresign', 'identify_methods', [['name' => 'email', 'enabled' => true]]);

		$items = $this->runSearch($appConfig, 'user@gmail', 'email', [
			'get' => ['user@gmail' => $this->enabledUser('user@gmail', 'Uid User')],
		]);
		$this->assertCount(1, $items);
		$this->assertSame('user@gmail', $items[0]['value']['shareWith']);
	}

	#[DataProvider('providerNonResolvingEmailInput')]
	public function testInvalidEmailInputDoesNotResolveViaEmailPath(string $search): void {
		$appConfig = $this->applyAppConfig(['shareapi_restrict_user_enumeration_full_match' => 'yes']);
		$appConfig->setValueArray('libresign', 'identify_methods', [['name' => 'email', 'enabled' => true]]);

		// getByEmail misses (not a primary email) and the query is not a UID either.
		$items = $this->runSearch($appConfig, $search, 'email', []);
		$this->assertCount(0, $items);
	}

	public static function providerNonResolvingEmailInput(): array {
		return [
			'partial domain' => ['user@gmail'],
			'trailing dot' => ['user@gmail.'],
			'bare at' => ['nobody@'],
			'invalid-like' => ['invalid-email@'],
			'unregistered exact-looking' => ['unregistered@example.com'],
		];
	}

	public function testGroupOnlyAllowsSharedNonExcludedGroup(): void {
		$appConfig = $this->applyAppConfig([
			'shareapi_restrict_user_enumeration_full_match' => 'yes',
			'shareapi_only_share_with_group_members' => 'yes',
		]);
		$appConfig->setValueArray('libresign', 'identify_methods', [['name' => 'email', 'enabled' => true]]);

		$items = $this->runSearch($appConfig, 'signer@example.com', 'email', [
			'getByEmail' => [$this->enabledUser('target', 'Target', ['customer-a'])],
		], ['customer-a']);
		$this->assertCount(1, $items);
	}

	public function testGroupOnlyRejectsWhenNoSharedGroup(): void {
		$appConfig = $this->applyAppConfig([
			'shareapi_restrict_user_enumeration_full_match' => 'yes',
			'shareapi_only_share_with_group_members' => 'yes',
		]);
		$appConfig->setValueArray('libresign', 'identify_methods', [['name' => 'email', 'enabled' => true]]);

		$items = $this->runSearch($appConfig, 'signer@example.com', 'email', [
			'getByEmail' => [$this->enabledUser('target', 'Target', ['customer-b'])],
		], ['customer-a']);
		$this->assertCount(0, $items);
	}

	public function testGroupOnlyRejectsWhenAllSharedGroupsExcluded(): void {
		$appConfig = $this->applyAppConfig([
			'shareapi_restrict_user_enumeration_full_match' => 'yes',
			'shareapi_only_share_with_group_members' => 'yes',
			'shareapi_only_share_with_group_members_exclude_group_list' => ['customer-a'],
		]);
		$appConfig->setValueArray('libresign', 'identify_methods', [['name' => 'email', 'enabled' => true]]);

		// Requester and target share only 'customer-a', which is excluded, so the
		// allowed-group intersection is empty and the candidate must be rejected.
		$items = $this->runSearch($appConfig, 'signer@example.com', 'email', [
			'getByEmail' => [$this->enabledUser('target', 'Target', ['customer-a'])],
		], ['customer-a']);
		$this->assertCount(0, $items);
	}

	public function testPluginDoesNotSelfExcludeSoDownstreamEnrichmentOwnsSelfSign(): void {
		// The plugin intentionally has no self-exclusion; ResultEnricher::addHerselfAccount
		// (unchanged, downstream) de-duplicates the requester and provides self-sign.
		$appConfig = $this->applyAppConfig(['shareapi_restrict_user_enumeration_full_match' => 'yes']);
		$appConfig->setValueArray('libresign', 'identify_methods', [['name' => 'email', 'enabled' => true]]);

		$items = $this->runSearch($appConfig, 'current@example.com', 'email', [
			'getByEmail' => [$this->enabledUser('current', 'Current User')],
		], [], 'current');
		$this->assertCount(1, $items);
		$this->assertSame('current', $items[0]['value']['shareWith']);
	}

	public function testPaginationHasMoreFlagAndSlicing(): void {
		$appConfig = $this->applyAppConfig(['shareapi_restrict_user_enumeration_full_match' => 'yes']);
		$appConfig->setValueArray('libresign', 'identify_methods', [['name' => 'email', 'enabled' => true]]);

		$users = [
			$this->enabledUser('a', 'A'),
			$this->enabledUser('b', 'B'),
			$this->enabledUser('c', 'C'),
		];
		[$items, $hasMore] = $this->runSearchWithReturn($appConfig, 'team@example.com', 'email', [
			'getByEmail' => $users,
		], [], 'current', 2, 0);
		$this->assertTrue($hasMore);
		$this->assertCount(2, $items);
	}

	private function enabledUser(string $uid, string $displayName, array $groups = []): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn(true);
		$user->method('getDisplayName')->willReturn($displayName);
		$this->userGroups[$uid] = $groups;
		return $user;
	}

	private function runSearch(
		\OCP\IAppConfig $appConfig,
		string $search,
		string $method,
		array $stubs,
		array $currentGroups = [],
		string $currentUid = 'current',
	): array {
		[$items] = $this->runSearchWithReturn($appConfig, $search, $method, $stubs, $currentGroups, $currentUid, 10, 0);
		return $items;
	}

	private function runSearchWithReturn(
		\OCP\IAppConfig $appConfig,
		string $search,
		string $method,
		array $stubs,
		array $currentGroups,
		string $currentUid,
		int $limit,
		int $offset,
	): array {
		$currentUser = $this->createStub(IUser::class);
		$currentUser->method('getUID')->willReturn($currentUid);
		$this->userGroups[$currentUid] = $currentGroups;

		$userSession = $this->createStub(IUserSession::class);
		$userSession->method('getUser')->willReturn($currentUser);

		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('getByEmail')->willReturn($stubs['getByEmail'] ?? []);
		$getMap = $stubs['get'] ?? [];
		$userManager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $getMap[$uid] ?? null);

		$groupManager = $this->createStub(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturnCallback(function (IUser $u): array {
			return $this->userGroups[$u->getUID()] ?? [];
		});

		$context = new SignerSearchContext();
		$context->set($method, $search, $search);

		$plugin = new EmailAccountPlugin(
			$appConfig,
			$groupManager,
			$userSession,
			$userManager,
			$context,
			$this->createStub(\Psr\Log\LoggerInterface::class),
		);

		$searchResult = new SearchResult();
		$hasMore = $plugin->search($search, $limit, $offset, $searchResult);
		$results = $searchResult->asArray();
		$items = array_merge($results['email-account'] ?? [], $results['exact']['email-account'] ?? []);
		return [$items, $hasMore];
	}

	private function applyAppConfig(array $config): \OCP\IAppConfig {
		$appConfig = $this->getMockAppConfigWithReset();
		foreach ($config as $key => $value) {
			if (is_array($value)) {
				$value = json_encode($value);
			}
			$appConfig->setValueString('core', $key, (string)$value);
		}
		return $appConfig;
	}
}
