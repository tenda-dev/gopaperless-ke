<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Identify;

use OC\Collaboration\Collaborators\SearchResult;
use OCA\Libresign\Collaboration\Collaborators\EmailAccountPlugin;
use OCA\Libresign\Service\Identify\AccountDiscoveryPolicy;
use OCA\Libresign\Service\Identify\SignerSearchContext;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins AccountDiscoveryPolicy to the EmailAccountPlugin's discovery gate.
 *
 * Stage 2 intentionally does NOT refactor the approved Stage 1 plugin onto the
 * shared policy (that would rewrite the plugin and its approved tests). Instead,
 * the policy duplicates the gate faithfully and this test proves that, for the
 * same stubs and config, the set of account uids the plugin emits is exactly the
 * set of uids the policy considers discoverable.
 */
class AccountDiscoveryPolicyParityTest extends TestCase {
	private const SEARCH = 'signer@example.com';

	public static function provider(): array {
		$defaults = [
			'shareapi_restrict_user_enumeration_full_match' => 'yes',
			'shareapi_restrict_user_enumeration_full_match_email' => 'yes',
			'shareapi_restrict_user_enumeration_full_match_user_id' => 'yes',
			'shareapi_only_share_with_group_members' => 'no',
			'shareapi_only_share_with_group_members_exclude_group_list' => '[]',
		];
		return [
			'defaults: discoverable via email' => [$defaults, true, ['g1'], ['g1'], true],
			'full_match master off: nothing' => [array_merge($defaults, ['shareapi_restrict_user_enumeration_full_match' => 'no']), true, ['g1'], ['g1'], true],
			'fm_email off: email path suppressed' => [array_merge($defaults, ['shareapi_restrict_user_enumeration_full_match_email' => 'no']), true, ['g1'], ['g1'], true],
			'group-only, shared group: discoverable' => [array_merge($defaults, ['shareapi_only_share_with_group_members' => 'yes']), true, ['g1'], ['g1'], true],
			'group-only, no shared group: suppressed' => [array_merge($defaults, ['shareapi_only_share_with_group_members' => 'yes']), true, ['g1'], [], true],
			'disabled user: suppressed' => [$defaults, true, ['g1'], ['g1'], false],
		];
	}

	#[DataProvider('provider')]
	public function testPluginEmissionMatchesPolicyDiscoverability(
		array $config,
		bool $emailEnabled,
		array $requesterGroups,
		array $targetGroups,
		bool $targetEnabled,
	): void {
		$target = $this->createStub(IUser::class);
		$target->method('getUID')->willReturn('target');
		$target->method('getDisplayName')->willReturn('Target User');
		$target->method('isEnabled')->willReturn($targetEnabled);

		$groups = ['requester' => $requesterGroups, 'target' => $targetGroups];

		$pluginUids = $this->runPlugin($config, $emailEnabled, $target, $groups);
		$policyUids = $this->runPolicyDiscoverable($config, $target, $groups);

		sort($pluginUids);
		sort($policyUids);
		$this->assertSame($pluginUids, $policyUids);
	}

	/** @return string[] */
	private function runPlugin(array $config, bool $emailEnabled, IUser $target, array $groups): array {
		$appConfig = $this->appConfig($config, $emailEnabled);
		$plugin = new EmailAccountPlugin(
			$appConfig,
			$this->groupManager($groups),
			$this->session(),
			$this->userManager($target),
			$this->context(),
			$this->createStub(\Psr\Log\LoggerInterface::class),
		);
		$searchResult = new SearchResult();
		$plugin->search(self::SEARCH, 10, 0, $searchResult);
		$results = $searchResult->asArray();
		$items = array_merge($results['email-account'] ?? [], $results['exact']['email-account'] ?? []);
		return array_map(static fn (array $i): string => $i['value']['shareWith'], $items);
	}

	/** @return string[] */
	private function runPolicyDiscoverable(array $config, IUser $target, array $groups): array {
		$policy = new AccountDiscoveryPolicy(
			$this->appConfig($config, true),
			$this->groupManager($groups),
			$this->session(),
			$this->userManager($target),
		);
		$uids = [];
		foreach ($policy->resolveCandidates(self::SEARCH) as $uid => $candidate) {
			if ($candidate['discoverable']) {
				$uids[] = $uid;
			}
		}
		return $uids;
	}

	private function appConfig(array $config, bool $emailEnabled): IAppConfig {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $config[$key] ?? $default
		);
		$methods = $emailEnabled ? [['name' => 'email', 'enabled' => true]] : [];
		$appConfig->method('getValueArray')->willReturn($methods);
		return $appConfig;
	}

	private function groupManager(array $groups): IGroupManager {
		$groupManager = $this->createStub(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $u): array => $groups[$u->getUID()] ?? []
		);
		return $groupManager;
	}

	private function userManager(IUser $target): IUserManager {
		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('getByEmail')->willReturn($target->isEnabled() ? [$target] : [$target]);
		$userManager->method('get')->willReturn(null);
		return $userManager;
	}

	private function session(): IUserSession {
		$requester = $this->createStub(IUser::class);
		$requester->method('getUID')->willReturn('requester');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($requester);
		return $session;
	}

	private function context(): SignerSearchContext {
		$context = new SignerSearchContext();
		$context->set('email', self::SEARCH, self::SEARCH);
		return $context;
	}
}
