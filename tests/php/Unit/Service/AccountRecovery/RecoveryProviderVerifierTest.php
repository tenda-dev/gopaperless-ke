<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\AccountRecovery;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\AccountRecovery\RecoveryProviderVerifier;
use OCA\Libresign\Service\UserOidcProviderService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecoveryProviderVerifierTest extends TestCase {
	/**
	 * configured id, what user_oidc offers (or an exception), expected verified id
	 *
	 * @return array<string, array{0: int, 1: array|\Throwable, 2: int}>
	 */
	public static function providerCases(): array {
		$offered = [['id' => 3, 'label' => 'Other'], ['id' => 5, 'label' => 'SecurySign']];

		return [
			'configured and offered' => [5, $offered, 5],
			'configured but not offered' => [7, $offered, 0],
			'configured, user_oidc disabled (empty list)' => [5, [], 0],
			'not configured' => [0, $offered, 0],
			'negative id' => [-1, $offered, 0],
			'discovery throws' => [5, new \RuntimeException('boom'), 0],
			'malformed option without id' => [5, [['label' => 'x']], 0],
		];
	}

	#[DataProvider('providerCases')]
	public function testVerifiedId(int $configured, array|\Throwable $offered, int $expected): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')
			->with(Application::APP_ID, 'securysign_provider_id', 0)
			->willReturn($configured);

		$providers = $this->createMock(UserOidcProviderService::class);
		if ($offered instanceof \Throwable) {
			$providers->method('getAvailableProviders')->willThrowException($offered);
		} else {
			$providers->method('getAvailableProviders')->willReturn($offered);
		}

		self::assertSame($expected, (new RecoveryProviderVerifier($appConfig, $providers))->verifiedId());
	}

	public function testDiscoveryIsNotAskedWhenNothingIsConfigured(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(0);
		$providers = $this->createMock(UserOidcProviderService::class);
		$providers->expects(self::never())->method('getAvailableProviders');

		self::assertSame(0, (new RecoveryProviderVerifier($appConfig, $providers))->verifiedId());
	}
}
