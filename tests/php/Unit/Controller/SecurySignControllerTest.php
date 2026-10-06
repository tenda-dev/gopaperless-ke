<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\SecurySignController;
use OCA\Libresign\Service\SecurySignService;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SecurySignControllerTest extends TestCase {
	private const IDENTITY = ['sub' => 'google-oauth2|1', 'issuer' => 'https://idp.test/realms/signa', 'accessToken' => 'at'];

	private SecurySignService&MockObject $signa;
	private ISession $session;
	private IUserSession $users;
	private SecurySignController $controller;
	/** @var array<string, mixed> */
	private array $store = [];

	protected function setUp(): void {
		$this->signa = $this->createMock(SecurySignService::class);
		$this->signa->method('applies')->willReturn(true);
		$this->signa->method('identity')->willReturn(self::IDENTITY);
		$this->signa->method('onboardingUrl')->willReturn('https://gopaperless.mimi.test/enrol');

		$this->store = [];
		$this->session = $this->createMock(ISession::class);
		$this->session->method('set')->willReturnCallback(function (string $key, $value): void {
			$this->store[$key] = $value;
		});
		$this->session->method('get')->willReturnCallback(fn (string $key) => $this->store[$key] ?? null);
		$this->session->method('remove')->willReturnCallback(function (string $key): void {
			unset($this->store[$key]);
		});

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice@example.test');
		$user->method('getEMailAddress')->willReturn('alice@example.test');
		$this->users = $this->createMock(IUserSession::class);
		$this->users->method('getUser')->willReturn($user);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(static fn (string $route): string => '/' . $route);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params) => 'https://gopaperless.test/apps/libresign/securysign/return?' . http_build_query($params),
		);
		$this->controller = new SecurySignController($this->createMock(IRequest::class), $this->signa, $this->session, $this->users, $urls, $this->createMock(LoggerInterface::class));
	}

	private function startOnboarding(): string {
		$response = $this->controller->onboard('/apps/libresign/f/document');
		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertStringStartsWith('https://gopaperless.mimi.test/enrol?', $response->getRedirectURL());
		parse_str((string)parse_url($response->getRedirectURL(), PHP_URL_QUERY), $query);
		// MIMI sets up this account, names it by email if it has to ask, and returns to our route.
		self::assertSame(self::IDENTITY['sub'], $query['sub']);
		self::assertSame('alice@example.test', $query['email']);
		parse_str((string)parse_url($query['returnTo'], PHP_URL_QUERY), $back);
		self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $back['state']);
		return $back['state'];
	}

	public function testAReadyUserIsSentStraightBackToTheirTask(): void {
		$this->signa->method('isReady')->willReturn(true);
		$this->session->expects(self::never())->method('set');

		$response = $this->controller->onboard('/apps/libresign/f/document');

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/apps/libresign/f/document', $response->getRedirectURL());
	}

	public function testOnboardingStoresANonceBoundToTheUserAndSubject(): void {
		$this->signa->method('isReady')->willReturn(false);

		$state = $this->startOnboarding();

		$pending = $this->store['libresign.securysign.onboarding'];
		self::assertSame($state, $pending['state']);
		self::assertSame(self::IDENTITY['sub'], $pending['sub']);
		self::assertSame('alice@example.test', $pending['uid']);
		self::assertSame('/apps/libresign/f/document', $pending['returnTo']);
		self::assertGreaterThan(time() + 3500, $pending['expires']);
	}

	public function testTheReturnJourneyResumesTheOriginalTaskOnceAndOnlyOnce(): void {
		$this->signa->method('isReady')->willReturnOnConsecutiveCalls(false, true, true);
		$state = $this->startOnboarding();

		$response = $this->controller->complete($state);

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/apps/libresign/f/document', $response->getRedirectURL());
		self::assertArrayNotHasKey('libresign.securysign.onboarding', $this->store);

		// Replay: the nonce is single use, so the same link only reaches the home page.
		$this->users->expects(self::never())->method('logout');
		self::assertSame('/apps/libresign/', $this->controller->complete($state)->getRedirectURL());
	}

	/** Stale, expired or foreign links go home. None of them may sign anyone out. */
	public function testABadLinkGoesHomeWithoutSigningOut(): void {
		$this->signa->method('isReady')->willReturn(false);
		$this->users->expects(self::never())->method('logout');
		$state = $this->startOnboarding();

		foreach (['', str_repeat('a', 64)] as $bad) {
			self::assertSame('/apps/libresign/', $this->controller->complete($bad)->getRedirectURL());
		}
		$this->store['libresign.securysign.onboarding']['uid'] = 'bob@example.test';
		self::assertSame('/apps/libresign/', $this->controller->complete($state)->getRedirectURL());
		$this->store['libresign.securysign.onboarding']['uid'] = 'alice@example.test';
		$this->store['libresign.securysign.onboarding']['expires'] = time() - 1;
		self::assertSame('/apps/libresign/', $this->controller->complete($state)->getRedirectURL());
	}

	/**
	 * Coming back unfinished (MIMI's logout lands here too) discards the setup:
	 * the user signs in again and the gate sends them back to MIMI if needed.
	 */
	public function testAnUnfinishedSetupSignsOutToTheLoginPage(): void {
		$this->signa->method('isReady')->willReturn(false);
		$this->users->expects(self::once())->method('logout');
		$state = $this->startOnboarding();

		$response = $this->controller->complete($state);

		self::assertSame('/core.login.showLoginForm', $response->getRedirectURL());
		self::assertArrayNotHasKey('libresign.securysign.onboarding', $this->store);
	}

	public function testAnotherSecurySignIdentitySignsOut(): void {
		$this->signa->method('isReady')->willReturn(false);
		$this->users->expects(self::once())->method('logout');
		$state = $this->startOnboarding();
		$this->store['libresign.securysign.onboarding']['sub'] = 'google-oauth2|2';

		self::assertSame('/core.login.showLoginForm', $this->controller->complete($state)->getRedirectURL());
	}

	/** An outage is not the user's problem: the page loads and the local engine signs. */
	public function testAnOutageGoesHomeUnnoticed(): void {
		$this->signa->method('isReady')->willThrowException(new \RuntimeException('down', 503));
		$this->signa->expects(self::once())->method('forgetReadiness');
		$this->users->expects(self::never())->method('logout');

		self::assertSame('/apps/libresign/f/document', $this->controller->onboard('/apps/libresign/f/document')->getRedirectURL());
	}

	public function testADeadSessionSignsOutToTheLoginPage(): void {
		$this->signa->method('isReady')->willThrowException(new \RuntimeException('expired', 401));
		$this->users->expects(self::once())->method('logout');

		self::assertSame('/core.login.showLoginForm', $this->controller->onboard('/apps/libresign/f/document')->getRedirectURL());
	}

	public function testAUserOutsideTheSecurySignProviderIsLeftAlone(): void {
		$signa = $this->createMock(SecurySignService::class);
		$signa->method('applies')->willReturn(false);
		$signa->expects(self::never())->method('isReady');
		$controller = new SecurySignController($this->createMock(IRequest::class), $signa, $this->session, $this->users, $this->createMock(IURLGenerator::class), $this->createMock(LoggerInterface::class));

		$response = $controller->onboard('/apps/libresign/f/document');

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/apps/libresign/f/document', $response->getRedirectURL());
	}

	/** The editor previews the card; the time is only known at signing, so it is not sent. */
	public function testTheEditorGetsTheCardWithoutATime(): void {
		$this->signa->method('signingContext')->willReturnOnConsecutiveCalls([
			'certificateSha256' => 'ab', 'name' => 'JANE NJOROGE', 'issuer' => 'Signa Hardware CA',
			'handwriting' => "\x89PNG", 'time' => new \DateTimeImmutable(), 'expiresAt' => new \DateTimeImmutable(),
		], null);

		$this->signa->method('signingCardLayout')->willReturn('horizontal');
		self::assertSame(['card' => [
			'name' => 'JANE NJOROGE',
			'issuer' => 'Signa Hardware CA',
			'handwriting' => 'data:image/png;base64,' . base64_encode("\x89PNG"),
		], 'layout' => 'horizontal'], $this->controller->card()->getData());
		self::assertSame(['card' => null, 'layout' => 'horizontal'], $this->controller->card()->getData());
	}
}
