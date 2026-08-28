<?php

declare(strict_types=1);

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\SsoController;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class SsoControllerTest extends TestCase {
	private IUserSession $userSession;
	private IURLGenerator $urlGenerator;
	private SsoController $controller;

	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->controller = new SsoController(
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->urlGenerator,
		);
	}

	public function testForceLogsOutBeforeStartingOidc(): void {
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
		$this->userSession->expects(self::never())->method('logout');
		$this->urlGenerator->method('linkToRoute')
			->with('user_oidc.login.login', ['providerId' => 2])
			->willReturn('/apps/user_oidc/login/2');

		$this->controller->handoff(2);
	}
}
