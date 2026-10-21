<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\TrustedOidcProviderPolicy;
use OCA\Libresign\Service\UserOidcProviderService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TrustedOidcProviderPolicyTest extends TestCase {
	private IAppManager&MockObject $appManager;
	private IAppConfig&MockObject $appConfig;
	private UserOidcProviderService&MockObject $providerService;
	private LoggerInterface&MockObject $logger;

	// Trusted provider ids for the stated deployment.
	private const MIMI_ID = 7;
	private const SECURYSIGN_ID = 9;

	protected function setUp(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->providerService = $this->createMock(UserOidcProviderService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function policy(): TrustedOidcProviderPolicy {
		return new TrustedOidcProviderPolicy(
			$this->appManager,
			$this->appConfig,
			$this->providerService,
			$this->logger,
		);
	}

	private function user(string $uid, string $backend): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getBackendClassName')->willReturn($backend);
		return $user;
	}

	/** Configure which trusted ids are set and which providers user_oidc exposes. */
	private function configure(int $mimiId, int $securysignId, array $configuredProviderIds, bool $oidcEnabled = true): void {
		$this->appManager->method('isEnabledForUser')->with('user_oidc')->willReturn($oidcEnabled);
		$this->appConfig->method('getValueInt')->willReturnMap([
			[Application::APP_ID, 'public_upload_login_provider_id', 0, $mimiId],
			[Application::APP_ID, 'securysign_provider_id', 0, $securysignId],
		]);
		$this->providerService->method('getAvailableProviders')->willReturn(
			array_map(static fn (int $id): array => ['id' => $id, 'label' => 'p' . $id], $configuredProviderIds),
		);
	}

	private const OIDC_BACKEND = 'OCA\\UserOIDC\\User\\Backend';

	public function testTrustsMimiAndSecurySignWhenBothAreTheOnlyProviders(): void {
		$this->configure(self::MIMI_ID, self::SECURYSIGN_ID, [self::MIMI_ID, self::SECURYSIGN_ID]);
		$this->assertTrue($this->policy()->isTrustedOidcAccount($this->user('me@example.com', self::OIDC_BACKEND)));
	}

	public function testTrustsWhenOnlyMimiIsConfigured(): void {
		$this->configure(self::MIMI_ID, 0, [self::MIMI_ID]);
		$this->assertTrue($this->policy()->isTrustedOidcAccount($this->user('me@example.com', self::OIDC_BACKEND)));
	}

	public function testTrustsWhenOnlySecurySignIsConfigured(): void {
		$this->configure(0, self::SECURYSIGN_ID, [self::SECURYSIGN_ID]);
		$this->assertTrue($this->policy()->isTrustedOidcAccount($this->user('me@example.com', self::OIDC_BACKEND)));
	}

	public function testWithholdsWhenAnUntrustedProviderIsAlsoConfigured(): void {
		// Mimi + SecurySign trusted, but a third (id 42) is present → we can no
		// longer guarantee the authenticating provider is trusted → withhold.
		$this->configure(self::MIMI_ID, self::SECURYSIGN_ID, [self::MIMI_ID, self::SECURYSIGN_ID, 42]);
		$this->assertFalse($this->policy()->isTrustedOidcAccount($this->user('me@example.com', self::OIDC_BACKEND)));
	}

	public function testWithholdsForLocalPasswordUser(): void {
		$this->configure(self::MIMI_ID, self::SECURYSIGN_ID, [self::MIMI_ID, self::SECURYSIGN_ID]);
		$this->assertFalse($this->policy()->isTrustedOidcAccount($this->user('me@example.com', 'Database')));
	}

	public function testWithholdsWhenOnlyProviderIsUntrusted(): void {
		$this->configure(self::MIMI_ID, self::SECURYSIGN_ID, [42]);
		$this->assertFalse($this->policy()->isTrustedOidcAccount($this->user('me@example.com', self::OIDC_BACKEND)));
	}

	public function testWithholdsWhenNoTrustedProviderConfigured(): void {
		$this->configure(0, 0, [42]);
		$this->assertFalse($this->policy()->isTrustedOidcAccount($this->user('me@example.com', self::OIDC_BACKEND)));
	}

	public function testWithholdsWhenNoProvidersEnumerated(): void {
		$this->configure(self::MIMI_ID, self::SECURYSIGN_ID, []);
		$this->assertFalse($this->policy()->isTrustedOidcAccount($this->user('me@example.com', self::OIDC_BACKEND)));
	}

	public function testWithholdsWhenUserOidcDisabled(): void {
		$this->configure(self::MIMI_ID, self::SECURYSIGN_ID, [self::MIMI_ID], false);
		$this->assertFalse($this->policy()->isTrustedOidcAccount($this->user('me@example.com', self::OIDC_BACKEND)));
	}

	public function testWithholdsForNullUser(): void {
		$this->assertFalse($this->policy()->isTrustedOidcAccount(null));
	}
}
