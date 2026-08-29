<?php

declare(strict_types=1);

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Controller\SsoController;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class SsoControllerTest extends TestCase {
	private IUserSession $userSession;
	private IURLGenerator $urlGenerator;
	private IAppConfig $appConfig;
	private SsoController $controller;

	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->controller = new SsoController(
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->urlGenerator,
			$this->appConfig,
		);
	}

	private function expectSsoEnabled(bool $enabled = true): void {
		$this->appConfig->method('getValueBool')
			->with(Application::APP_ID, 'oidc_sso_handoff_enabled', false)
			->willReturn($enabled);
	}

	public function testFeatureFlagDisabledRedirectsToAppRoot(): void {
		$this->expectSsoEnabled(false);
		$this->userSession->expects(self::never())->method('logout');
		$this->urlGenerator->expects(self::once())
			->method('linkToRoute')
			->with('libresign.page.index')
			->willReturn('/apps/libresign/');

		$response = $this->controller->handoff(2, 1, '/apps/libresign/f/');

		self::assertSame('/apps/libresign/', $response->getRedirectURL());
	}

	public function testForceLogsOutBeforeStartingOidc(): void {
		$this->expectSsoEnabled();
		$this->userSession->method('isLoggedIn')->willReturn(true);
		$this->userSession->expects(self::once())->method('logout');
		$this->urlGenerator->method('linkToRoute')
			->with('user_oidc.login.login', [
				'providerId' => 2,
				'redirectUrl' => '/apps/libresign/f/',
			])
			->willReturn('/apps/user_oidc/login/2?redirectUrl=%2Fapps%2Flibresign%2Ff%2F');

		$response = $this->controller->handoff(2, 1, '/apps/libresign/f/');

		self::assertSame('/apps/user_oidc/login/2?redirectUrl=%2Fapps%2Flibresign%2Ff%2F', $response->getRedirectURL());
	}

	public function testNormalHandoffKeepsTheCurrentSession(): void {
		$this->expectSsoEnabled();
		$this->userSession->expects(self::never())->method('logout');
		$this->urlGenerator->expects(self::exactly(2))
			->method('linkToRoute')
			->willReturnCallback(function (string $route, array $params = []): string {
				if ($route === 'libresign.page.index') {
					self::assertSame([], $params);
					return '/apps/libresign/';
				}

				self::assertSame('user_oidc.login.login', $route);
				self::assertSame([
					'providerId' => 2,
					'redirectUrl' => '/apps/libresign/',
				], $params);
				return '/apps/user_oidc/login/2?redirectUrl=%2Fapps%2Flibresign%2F';
			});

		$response = $this->controller->handoff(2);

		self::assertSame('/apps/user_oidc/login/2?redirectUrl=%2Fapps%2Flibresign%2F', $response->getRedirectURL());
	}

	public function testExternalRedirectUrlIsSanitized(): void {
		$this->expectSsoEnabled();
		$this->userSession->expects(self::never())->method('logout');
		$this->urlGenerator->expects(self::exactly(2))
			->method('linkToRoute')
			->willReturnCallback(function (string $route, array $params = []): string {
				if ($route === 'libresign.page.index') {
					return '/apps/libresign/';
				}

				self::assertSame('user_oidc.login.login', $route);
				self::assertSame([
					'providerId' => 2,
					'redirectUrl' => '/apps/libresign/',
				], $params);
				return '/apps/user_oidc/login/2?redirectUrl=%2Fapps%2Flibresign%2F';
			});

		$response = $this->controller->handoff(2, 0, 'https://attacker.example.com/callback');

		self::assertSame('/apps/user_oidc/login/2?redirectUrl=%2Fapps%2Flibresign%2F', $response->getRedirectURL());
	}

	public function testProtocolRelativeRedirectUrlIsSanitized(): void {
		$this->expectSsoEnabled();
		$this->userSession->expects(self::never())->method('logout');
		$this->urlGenerator->expects(self::once())
			->method('linkToRoute')
			->with('libresign.page.index')
			->willReturn('/apps/libresign/');

		$response = $this->controller->handoff(2, 0, '//attacker.example.com/apps/libresign/');

		self::assertSame('/apps/libresign/', $response->getRedirectURL());
	}

	public function testAbsoluteSameOriginRedirectUrlIsAllowed(): void {
		$this->expectSsoEnabled();
		$this->userSession->expects(self::never())->method('logout');
		$this->urlGenerator->method('getBaseUrl')->willReturn('https://signa.example.com');
		$this->urlGenerator->method('linkToRoute')
			->with('user_oidc.login.login', [
				'providerId' => 2,
				'redirectUrl' => 'https://signa.example.com/apps/libresign/f/request',
			])
			->willReturn('/apps/user_oidc/login/2?redirectUrl=%2Fapps%2Flibresign%2Ff%2Frequest');

		$response = $this->controller->handoff(2, 0, 'https://signa.example.com/apps/libresign/f/request');

		self::assertSame('/apps/user_oidc/login/2?redirectUrl=%2Fapps%2Flibresign%2Ff%2Frequest', $response->getRedirectURL());
	}
}
