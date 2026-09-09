<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * `\OCA\UserOIDC\AlternativeLogin\AlternativeLoginProvider` does not exist in
 * this test environment (User OIDC is not installed here), so in production
 * code `class_exists()` naturally returns false and
 * UserOidcProviderService::getAvailableProviders() naturally returns
 * [] -- covering the most important case, User OIDC being absent, without
 * any test double at all.
 *
 * To also exercise the discovery/parsing logic for the "User OIDC present"
 * path, this file declares a minimal fake implementation of that exact
 * class (and of OCP\Authentication\IAlternativeLogin) using PHP's bracketed
 * multi-namespace syntax, so both are defined the moment this test file is
 * loaded -- without touching composer.json's autoload rules or adding a
 * separate fixtures class under an unrelated namespace.
 */

namespace OCA\UserOIDC\AlternativeLogin {

	use OCP\Authentication\IAlternativeLogin;
	use OCP\Authentication\IAlternativeLoginProvider;

	class AlternativeLoginProvider implements IAlternativeLoginProvider {
		/** @var list<IAlternativeLogin> */
		public static array $alternativeLogins = [];

		#[\Override]
		public function getAlternativeLogins(): array {
			return self::$alternativeLogins;
		}
	}
}

namespace OCA\Libresign\Tests\Unit\Service {

	use OCA\Libresign\Service\UserOidcProviderService;
	use OCA\Libresign\Tests\Unit\TestCase;
	use OCA\UserOIDC\AlternativeLogin\AlternativeLoginProvider;
	use OCP\App\IAppManager;
	use OCP\Authentication\IAlternativeLogin;
	use PHPUnit\Framework\MockObject\MockObject;
	use Psr\Log\LoggerInterface;

	final class FakeAlternativeLogin implements IAlternativeLogin {
		public function __construct(
			private string $label,
			private string $link,
		) {
		}

		#[\Override]
		public function getLabel(): string {
			return $this->label;
		}

		#[\Override]
		public function getLink(): string {
			return $this->link;
		}

		#[\Override]
		public function getClass(): string {
			return '';
		}

		#[\Override]
		public function load(): void {
		}
	}

	final class UserOidcProviderServiceTest extends TestCase {
		private IAppManager&MockObject $appManager;
		private LoggerInterface&MockObject $logger;
		private UserOidcProviderService $service;

		public function setUp(): void {
			AlternativeLoginProvider::$alternativeLogins = [];

			$this->appManager = $this->createMock(IAppManager::class);
			$this->logger = $this->createMock(LoggerInterface::class);
			$this->service = new UserOidcProviderService($this->appManager, $this->logger);
		}

		public function testReturnsNoProvidersWhenUserOidcIsNotEnabled(): void {
			$this->appManager->method('isEnabledForUser')->with('user_oidc')->willReturn(false);

			$this->assertSame([], $this->service->getAvailableProviders());
		}

		public function testReturnsConfiguredProvidersWhenUserOidcIsEnabled(): void {
			$this->appManager->method('isEnabledForUser')->with('user_oidc')->willReturn(true);
			AlternativeLoginProvider::$alternativeLogins = [
				new FakeAlternativeLogin('GoPaperless OIDC', '/apps/user_oidc/login/2?redirectUrl=%2Fapps%2Flibresign%2F'),
				new FakeAlternativeLogin('Microsoft Entra ID', '/apps/user_oidc/login/5'),
			];

			$providers = $this->service->getAvailableProviders();

			$this->assertSame([
				['id' => 2, 'label' => 'GoPaperless OIDC'],
				['id' => 5, 'label' => 'Microsoft Entra ID'],
			], $providers);
		}

		public function testSkipsAlternativeLoginsThatAreNotUserOidcProviderLinks(): void {
			$this->appManager->method('isEnabledForUser')->with('user_oidc')->willReturn(true);
			AlternativeLoginProvider::$alternativeLogins = [
				// e.g. User OIDC's own ID4ME alternative login option, which
				// does not point at /login/{providerId}.
				new FakeAlternativeLogin('ID4ME', '/apps/user_oidc/id4me'),
				new FakeAlternativeLogin('GoPaperless OIDC', '/apps/user_oidc/login/2'),
			];

			$providers = $this->service->getAvailableProviders();

			$this->assertSame([
				['id' => 2, 'label' => 'GoPaperless OIDC'],
			], $providers);
		}
	}
}
