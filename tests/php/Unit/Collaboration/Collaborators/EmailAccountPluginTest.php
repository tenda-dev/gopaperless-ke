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
			'restrict to group without common group' => [
				'method' => 'email',
				'config' => [
					'shareapi_allow_share_dialog_user_enumeration' => 'yes',
					'shareapi_restrict_user_enumeration_to_group' => 'yes',
				],
				'enabled' => true,
				'currentGroups' => ['sales'],
				'targetGroups' => ['engineering'],
				'userEnabled' => true,
				'expectedCount' => 0,
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
