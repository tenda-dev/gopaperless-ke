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
use OCP\AppFramework\Http\TemplateResponse;
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
		$this->signa->method('onboardingUrl')->willReturn('https://tendaworld.test/onboarding/gopaperless');

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
		$this->users = $this->createMock(IUserSession::class);
		$this->users->method('getUser')->willReturn($user);

		$this->controller = new SecurySignController($this->createMock(IRequest::class), $this->signa, $this->session, $this->users, $this->createMock(IURLGenerator::class), $this->createMock(LoggerInterface::class));
	}

	private function startOnboarding(): string {
		$response = $this->controller->onboard('/apps/libresign/f/document');
		self::assertInstanceOf(RedirectResponse::class, $response);
		parse_str((string)parse_url($response->getRedirectURL(), PHP_URL_QUERY), $query);
		self::assertSame(self::IDENTITY['sub'], $query['subject']);
		self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $query['state']);
		return $query['state'];
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

		// Replay: the nonce is single use, so the same link cannot be walked again.
		self::assertInstanceOf(TemplateResponse::class, $this->controller->complete($state));
	}

	public function testAWrongOrEmptyStateIsRefused(): void {
		$this->signa->method('isReady')->willReturn(false);
		$this->startOnboarding();

		foreach (['', str_repeat('a', 64)] as $state) {
			self::assertInstanceOf(TemplateResponse::class, $this->controller->complete($state));
		}
		self::assertArrayHasKey('libresign.securysign.onboarding', $this->store);
	}

	public function testAnExpiredNonceIsRefused(): void {
		$this->signa->method('isReady')->willReturn(false);
		$state = $this->startOnboarding();
		$this->store['libresign.securysign.onboarding']['expires'] = time() - 1;

		self::assertInstanceOf(TemplateResponse::class, $this->controller->complete($state));
	}

	public function testANonceFromAnotherIdentityIsRefused(): void {
		$this->signa->method('isReady')->willReturn(false);
		$state = $this->startOnboarding();

		$this->store['libresign.securysign.onboarding']['sub'] = 'google-oauth2|2';
		self::assertInstanceOf(TemplateResponse::class, $this->controller->complete($state));

		$this->store['libresign.securysign.onboarding']['sub'] = self::IDENTITY['sub'];
		$this->store['libresign.securysign.onboarding']['uid'] = 'bob@example.test';
		self::assertInstanceOf(TemplateResponse::class, $this->controller->complete($state));
	}

	public function testReturningBeforeSecurySignIsReadyDoesNotConsumeTheNonce(): void {
		$this->signa->method('isReady')->willReturn(false);
		$state = $this->startOnboarding();

		$response = $this->controller->complete($state);

		self::assertInstanceOf(TemplateResponse::class, $response);
		self::assertSame(503, $response->getStatus());
		self::assertArrayHasKey('libresign.securysign.onboarding', $this->store);
	}

	public function testASecurySignOutageIsReportedInsteadOfLoopingOrSigning(): void {
		$this->signa->method('isReady')->willThrowException(new \RuntimeException('down', 503));

		self::assertInstanceOf(TemplateResponse::class, $this->controller->onboard('/apps/libresign/f/document'));
		self::assertInstanceOf(TemplateResponse::class, $this->controller->complete(str_repeat('a', 64)));
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
}
