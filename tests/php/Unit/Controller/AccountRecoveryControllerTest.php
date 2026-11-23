<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\AccountRecoveryController;
use OCA\Libresign\Db\IdentifyMethod;
use OCA\Libresign\Db\IdentifyMethodMapper;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Helper\RecoveryCookie;
use OCA\Libresign\Service\AccountRecovery\RecoveryProviderVerifier;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AccountRecoveryControllerTest extends TestCase {
	private const UUID = '0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10';
	private const SIGN_PAGE = '/apps/libresign/p/sign/0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10';
	private const INDEX_PAGE = '/apps/libresign/';
	private const COMPLETE_PAGE = '/apps/libresign/account/recover/complete';
	private const SIGNER_EMAIL = 'testuser@gmail.com';
	private const SECRET = 'instance-secret-A';

	private IUserSession&MockObject $userSession;
	private IUserManager&MockObject $userManager;
	private IURLGenerator&MockObject $urlGenerator;
	private IRequest&MockObject $request;
	private SignRequestMapper&MockObject $signRequestMapper;
	private IdentifyMethodMapper&MockObject $identifyMethodMapper;
	private RecoveryProviderVerifier&MockObject $providerVerifier;
	private RecoveryCookie $recoveryCookie;
	/** @var array<string, mixed> */
	private array $config = [];
	private string $signUrl = self::SIGN_PAGE;
	private string $baseUrl = 'https://nc.example.com';
	private string $webroot = '';
	private ?string $incomingCookie = null;
	private AccountRecoveryController $controller;

	protected function setUp(): void {
		$this->config = [
			'whatsapp_enabled' => false,
			'whatsapp_api_url' => '',
			'whatsapp_api_key' => '',
		];
		$this->signUrl = self::SIGN_PAGE;
		$this->baseUrl = 'https://nc.example.com';
		$this->webroot = '';
		$this->incomingCookie = null;

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(fn (string $app, string $key, bool $default = false): bool => (bool)($this->config[$key] ?? $default));
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => (string)($this->config[$key] ?? $default));

		$systemConfig = $this->createMock(IConfig::class);
		$systemConfig->method('getSystemValueString')->willReturnCallback(fn (): string => (string)($this->config['secret'] ?? self::SECRET));
		$this->recoveryCookie = new RecoveryCookie($systemConfig);

		$this->providerVerifier = $this->createMock(RecoveryProviderVerifier::class);
		$this->providerVerifier->method('verifiedId')->willReturn(0);

		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->signRequestMapper = $this->createMock(SignRequestMapper::class);
		$this->identifyMethodMapper = $this->createMock(IdentifyMethodMapper::class);

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getCookie')->willReturnCallback(fn (string $name): ?string => $name === RecoveryCookie::NAME ? $this->incomingCookie : null);

		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('getBaseUrl')->willReturnCallback(fn (): string => $this->baseUrl);
		$this->urlGenerator->method('getWebroot')->willReturnCallback(fn (): string => $this->webroot);
		$this->urlGenerator->method('linkToRoute')->willReturnCallback(function (string $route, array $params = []): string {
			return match ($route) {
				'libresign.page.sign' => $this->signUrl,
				'libresign.page.index' => self::INDEX_PAGE,
				'libresign.accountRecovery.recoverComplete' => self::COMPLETE_PAGE,
				'user_oidc.login.login' => '/apps/user_oidc/login/' . $params['providerId'] . '?redirectUrl=' . rawurlencode($params['redirectUrl']),
				'core.login.showLoginForm' => '/login?' . http_build_query($params),
				default => self::fail('unexpected route ' . $route),
			};
		});

		$this->controller = $this->buildController($this->urlGenerator, $appConfig);
	}

	private function buildController(IURLGenerator $urlGenerator, ?IAppConfig $appConfig = null): AccountRecoveryController {
		return new AccountRecoveryController(
			$this->request,
			$this->userSession,
			$this->userManager,
			$urlGenerator,
			$appConfig ?? $this->createMock(IAppConfig::class),
			$this->signRequestMapper,
			$this->identifyMethodMapper,
			$this->providerVerifier,
			$this->recoveryCookie,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function user(string $uid, string $backend, ?string $email = null): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getBackendClassName')->willReturn($backend);
		$user->method('getEMailAddress')->willReturn($email);

		return $user;
	}

	private function givenSignRequestFor(IUser $signer): void {
		$signRequest = new SignRequest();
		$signRequest->setId(10);
		$this->signRequestMapper->method('getByUuid')->with(self::UUID)->willReturn($signRequest);

		$method = new IdentifyMethod();
		$method->setIdentifierKey('account');
		$method->setIdentifierValue($signer->getUID());
		$this->identifyMethodMapper->method('getIdentifyMethodsFromSignRequestId')->with(10)->willReturn([$method]);
		$this->userManager->method('get')->with($signer->getUID())->willReturn($signer);
	}

	private function assertNoCookieTouched(Response $response): void {
		self::assertSame([], $response->getCookies());
	}

	// ---- routes and attributes -------------------------------------------------

	public function testSwitchRouteIsAuthenticatedPostWithCsrfAndNoDestinationParameter(): void {
		$method = new \ReflectionMethod(AccountRecoveryController::class, 'switchAccount');

		self::assertCount(1, $method->getAttributes(NoAdminRequired::class));
		self::assertCount(1, $method->getAttributes(UseSession::class), 'logout needs a writable session');
		self::assertCount(0, $method->getAttributes(PublicPage::class), 'must be authenticated');
		self::assertCount(0, $method->getAttributes(NoCSRFRequired::class), 'Nextcloud must validate requesttoken');

		$routes = $method->getAttributes(FrontpageRoute::class);
		self::assertCount(1, $routes);
		$route = $routes[0]->newInstance();
		self::assertSame('POST', $route->getVerb());
		self::assertSame('/account/switch', $route->getUrl());

		self::assertSame(['uuid'], array_map(fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters()));
	}

	public function testCompleteRouteIsAnAuthenticatedNavigationWithoutParameters(): void {
		$method = new \ReflectionMethod(AccountRecoveryController::class, 'recoverComplete');

		self::assertCount(1, $method->getAttributes(NoAdminRequired::class));
		self::assertCount(1, $method->getAttributes(UseSession::class));
		self::assertCount(1, $method->getAttributes(NoCSRFRequired::class), 'a redirect from the login page carries no requesttoken');
		self::assertCount(0, $method->getAttributes(PublicPage::class), 'a session is required');

		$routes = $method->getAttributes(FrontpageRoute::class);
		self::assertCount(1, $routes);
		$route = $routes[0]->newInstance();
		self::assertSame('GET', $route->getVerb());
		self::assertSame('/account/recover/complete', $route->getUrl());

		self::assertSame([], $method->getParameters(), 'no query parameter is trusted');
	}

	// ---- switchAccount: guards (plain redirect, no cookie, no logout) ----------

	public function testUnauthenticatedGetsThePlainRedirectWithoutLogout(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->userSession->expects(self::never())->method('logout');

		$response = $this->controller->switchAccount(self::UUID);

		self::assertSame(self::SIGN_PAGE, $response->getRedirectURL());
		$this->assertNoCookieTouched($response);
	}

	public function testWhatsAppOnGetsThePlainRedirectWithoutLogout(): void {
		$this->config['whatsapp_enabled'] = true;
		$this->config['whatsapp_api_url'] = 'https://wa.example.test';
		$this->config['whatsapp_api_key'] = 'key';
		$this->userSession->method('getUser')->willReturn($this->user('signer2', 'Database'));
		$this->givenSignRequestFor($this->user('signer1', 'Database'));
		$this->userSession->expects(self::never())->method('logout');

		$response = $this->controller->switchAccount(self::UUID);

		self::assertSame(self::SIGN_PAGE, $response->getRedirectURL());
		$this->assertNoCookieTouched($response);
	}

	public function testMalformedUuidGetsTheIndexRedirectWithoutLogout(): void {
		$this->userSession->method('getUser')->willReturn($this->user('signer2', 'Database'));
		$this->userSession->expects(self::never())->method('logout');
		$this->signRequestMapper->expects(self::never())->method('getByUuid');

		foreach (['', 'not-a-uuid', '../../settings', self::UUID . '/x', self::UUID . "\n", 'https://evil.example/'] as $uuid) {
			$response = $this->controller->switchAccount($uuid);
			self::assertSame(self::INDEX_PAGE, $response->getRedirectURL(), $uuid);
			$this->assertNoCookieTouched($response);
		}
	}

	public function testUnknownUuidGetsThePlainRedirectWithoutLogout(): void {
		$this->userSession->method('getUser')->willReturn($this->user('signer2', 'Database'));
		$this->signRequestMapper->method('getByUuid')->willThrowException(new DoesNotExistException(''));
		$this->userSession->expects(self::never())->method('logout');

		$response = $this->controller->switchAccount(self::UUID);

		self::assertSame(self::SIGN_PAGE, $response->getRedirectURL());
		$this->assertNoCookieTouched($response);
	}

	public function testTheSignerThemselvesIsNotLoggedOut(): void {
		$signer = $this->user('signer1', 'Database');
		$this->userSession->method('getUser')->willReturn($this->user('signer1', 'Database'));
		$this->givenSignRequestFor($signer);
		$this->userSession->expects(self::never())->method('logout');

		$response = $this->controller->switchAccount(self::UUID);

		self::assertSame(self::SIGN_PAGE, $response->getRedirectURL());
		$this->assertNoCookieTouched($response);
	}

	public function testSeveralAccountsSharingTheEmailAreUnresolvedAndNobodyIsLoggedOut(): void {
		$this->userSession->method('getUser')->willReturn($this->user('signer2', 'Database'));
		$signRequest = new SignRequest();
		$signRequest->setId(10);
		$this->signRequestMapper->method('getByUuid')->willReturn($signRequest);
		$method = new IdentifyMethod();
		$method->setIdentifierKey('account');
		$method->setIdentifierValue('shared@domain.test');
		$this->identifyMethodMapper->method('getIdentifyMethodsFromSignRequestId')->willReturn([$method]);
		$this->userManager->method('get')->willReturn(null);
		$this->userManager->method('getByEmail')->willReturn([$this->user('a', 'Database'), $this->user('b', 'Database')]);
		$this->userSession->expects(self::never())->method('logout');

		$response = $this->controller->switchAccount(self::UUID);

		self::assertSame(self::SIGN_PAGE, $response->getRedirectURL());
		$this->assertNoCookieTouched($response);
	}

	public function testClientSlsPlanIsLeftToThePageWithoutCookieOrLogout(): void {
		$this->providerVerifier = $this->createMock(RecoveryProviderVerifier::class);
		$this->providerVerifier->method('verifiedId')->willReturn(5);
		$this->controller = $this->buildController($this->urlGenerator);
		$this->userSession->method('getUser')->willReturn($this->user('signer2', 'user_oidc'));
		$this->givenSignRequestFor($this->user('signer1', 'user_oidc'));
		$this->userSession->expects(self::never())->method('logout');

		$response = $this->controller->switchAccount(self::UUID);

		self::assertSame(self::SIGN_PAGE, $response->getRedirectURL());
		$this->assertNoCookieTouched($response);
	}

	public function testNoTrustworthyCookieMeansNoLogout(): void {
		$this->config['secret'] = '';
		$this->userSession->method('getUser')->willReturn($this->user('signer2', 'Database'));
		$this->givenSignRequestFor($this->user('signer1', 'Database'));
		$this->userSession->expects(self::never())->method('logout');

		$response = $this->controller->switchAccount(self::UUID);

		self::assertSame(self::SIGN_PAGE, $response->getRedirectURL());
		$this->assertNoCookieTouched($response);
	}

	// ---- switchAccount: server-handled plans -----------------------------------

	private function assertRecoveryCookieFor(Response $response, string $signerUid): void {
		$cookies = $response->getCookies();
		self::assertSame([RecoveryCookie::NAME], array_keys($cookies));
		self::assertSame('Lax', $cookies[RecoveryCookie::NAME]['sameSite']);
		self::assertNotNull($cookies[RecoveryCookie::NAME]['expireDate']);
		$ttl = $cookies[RecoveryCookie::NAME]['expireDate']->getTimestamp() - time();
		self::assertTrue($ttl > RecoveryCookie::TTL - 5 && $ttl <= RecoveryCookie::TTL, "expiry is now + TTL, got $ttl");

		$verified = $this->recoveryCookie->verify($cookies[RecoveryCookie::NAME]['value']);
		self::assertNotNull($verified);
		self::assertSame(self::UUID, $verified['uuid']);
		self::assertTrue($this->recoveryCookie->isBoundTo($verified, $signerUid));
		self::assertFalse($this->recoveryCookie->isBoundTo($verified, 'signer2'));
		self::assertStringNotContainsString($signerUid, $cookies[RecoveryCookie::NAME]['value']);
	}

	public function testNativeSignerLogsOutAndGoesToThePasswordFormThenRecoverComplete(): void {
		$this->userSession->method('getUser')->willReturn($this->user('signer2', 'Database'));
		$this->givenSignRequestFor($this->user('signer1', 'Database', self::SIGNER_EMAIL));
		$this->userSession->expects(self::once())->method('logout');

		$response = $this->controller->switchAccount(self::UUID);

		self::assertSame('/login?' . http_build_query(['direct' => 1, 'redirect_url' => self::COMPLETE_PAGE]), $response->getRedirectURL());
		self::assertStringNotContainsString('testuser', $response->getRedirectURL());
		self::assertStringNotContainsString(self::UUID, $response->getRedirectURL(), 'the raw sign page is never the login destination');
		$this->assertRecoveryCookieFor($response, 'signer1');
	}

	public function testOidcSignerWithVerifiedProviderGoesToTheProviderThenRecoverComplete(): void {
		$this->providerVerifier = $this->createMock(RecoveryProviderVerifier::class);
		$this->providerVerifier->method('verifiedId')->willReturn(5);
		$this->controller = $this->buildController($this->urlGenerator);
		$this->userSession->method('getUser')->willReturn($this->user('signer2', 'Database'));
		$this->givenSignRequestFor($this->user('signer1', 'user_oidc', self::SIGNER_EMAIL));
		$this->userSession->expects(self::once())->method('logout');

		$response = $this->controller->switchAccount(self::UUID);

		self::assertSame('/apps/user_oidc/login/5?redirectUrl=' . rawurlencode(self::COMPLETE_PAGE), $response->getRedirectURL());
		self::assertStringNotContainsString('testuser', $response->getRedirectURL());
		$this->assertRecoveryCookieFor($response, 'signer1');
	}

	public function testOidcSignerWithoutAVerifiedProviderGoesToTheGenericLogin(): void {
		// The verifier answers 0: not configured, not offered, or user_oidc disabled.
		$this->userSession->method('getUser')->willReturn($this->user('signer2', 'user_oidc'));
		$this->givenSignRequestFor($this->user('signer1', 'user_oidc', self::SIGNER_EMAIL));
		$this->userSession->expects(self::once())->method('logout');

		$response = $this->controller->switchAccount(self::UUID);

		self::assertSame('/login?' . http_build_query(['redirect_url' => self::COMPLETE_PAGE]), $response->getRedirectURL());
		$this->assertRecoveryCookieFor($response, 'signer1');
	}

	public function testUnknownBackendSignerGoesToTheGenericLogin(): void {
		$this->userSession->method('getUser')->willReturn($this->user('signer2', 'Database'));
		$this->givenSignRequestFor($this->user('signer1', 'LDAP', self::SIGNER_EMAIL));
		$this->userSession->expects(self::once())->method('logout');

		$response = $this->controller->switchAccount(self::UUID);

		self::assertSame('/login?' . http_build_query(['redirect_url' => self::COMPLETE_PAGE]), $response->getRedirectURL());
		$this->assertRecoveryCookieFor($response, 'signer1');
	}

	public function testLogoutHappensAfterTheTargetIsComputed(): void {
		$this->userSession->method('getUser')->willReturn($this->user('signer2', 'Database'));
		$this->givenSignRequestFor($this->user('signer1', 'Database'));
		$order = [];
		$this->userSession->method('logout')->willReturnCallback(function () use (&$order): void {
			$order[] = 'logout';
		});
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(function (string $route) use (&$order): string {
			$order[] = $route;
			return '/x';
		});

		$this->buildController($urlGenerator)->switchAccount(self::UUID);

		self::assertSame('logout', end($order));
	}

	// ---- recoverComplete ------------------------------------------------------

	private function validCookieFor(string $uid, ?int $now = null): string {
		$value = $this->recoveryCookie->issue(self::UUID, $uid, $now);
		self::assertNotNull($value);

		return $value;
	}

	private function assertCookieCleared(Response $response): void {
		$cookies = $response->getCookies();
		self::assertSame([RecoveryCookie::NAME], array_keys($cookies));
		self::assertSame('expired', $cookies[RecoveryCookie::NAME]['value']);
		self::assertTrue($cookies[RecoveryCookie::NAME]['expireDate']->getTimestamp() < time());
	}

	public function testCompleteForTheBoundAccountClearsTheCookieAndGoesToTheSignPage(): void {
		$this->incomingCookie = $this->validCookieFor('signer1');
		$this->userSession->method('getUser')->willReturn($this->user('signer1', 'Database'));

		$response = $this->controller->recoverComplete();

		self::assertSame(self::SIGN_PAGE, $response->getRedirectURL());
		$this->assertCookieCleared($response);
	}

	public function testCompleteForAWrongAccountClearsTheCookieAndGoesToTheIndex(): void {
		$this->incomingCookie = $this->validCookieFor('signer1');
		$this->userSession->method('getUser')->willReturn($this->user('signer2', 'Database'));

		$response = $this->controller->recoverComplete();

		self::assertSame(self::INDEX_PAGE, $response->getRedirectURL(), 'the intended destination is not revealed');
		$this->assertCookieCleared($response);
	}

	public function testCompleteForAnAccountSharingTheEmailButNotTheUidIsStillAMismatch(): void {
		$this->incomingCookie = $this->validCookieFor('signer1');
		$other = $this->user('signer2', 'Database', 'shared@example.test');
		$this->userSession->method('getUser')->willReturn($other);

		$response = $this->controller->recoverComplete();

		self::assertSame(self::INDEX_PAGE, $response->getRedirectURL());
		$this->assertCookieCleared($response);
	}

	public function testCompleteWithoutASessionUserClearsTheCookieAndGoesToTheIndex(): void {
		$this->incomingCookie = $this->validCookieFor('signer1');
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->recoverComplete();

		self::assertSame(self::INDEX_PAGE, $response->getRedirectURL());
		$this->assertCookieCleared($response);
	}

	/** @return array<string, array{0: ?string}> */
	public static function unusableCookieProvider(): array {
		return [
			'no cookie' => [null],
			'empty' => [''],
			'garbage' => ['not-a-cookie'],
			'dot soup' => ['.....'],
		];
	}

	#[DataProvider('unusableCookieProvider')]
	public function testCompleteWithoutAUsableCookieClearsItAndGoesToTheIndexNotTheLogin(?string $cookie): void {
		$this->incomingCookie = $cookie;
		$this->userSession->method('getUser')->willReturn($this->user('signer1', 'Database'));

		$response = $this->controller->recoverComplete();

		self::assertSame(self::INDEX_PAGE, $response->getRedirectURL());
		$this->assertCookieCleared($response);
	}

	public function testCompleteWithAnExpiredCookieClearsItAndGoesToTheIndex(): void {
		$this->incomingCookie = $this->validCookieFor('signer1', time() - 2 * RecoveryCookie::TTL);
		$this->userSession->method('getUser')->willReturn($this->user('signer1', 'Database'));

		$response = $this->controller->recoverComplete();

		self::assertSame(self::INDEX_PAGE, $response->getRedirectURL());
		$this->assertCookieCleared($response);
	}

	public function testCompleteWithATamperedCookieClearsItAndGoesToTheIndex(): void {
		$value = $this->validCookieFor('signer1');
		$this->incomingCookie = str_replace(self::UUID, '11111111-2222-4333-8444-555555555555', $value);
		$this->userSession->method('getUser')->willReturn($this->user('signer1', 'Database'));

		$response = $this->controller->recoverComplete();

		self::assertSame(self::INDEX_PAGE, $response->getRedirectURL());
		$this->assertCookieCleared($response);
	}

	public function testCompleteWithACookieFromAnotherSecretIsInvalid(): void {
		$this->incomingCookie = $this->validCookieFor('signer1');
		$this->config['secret'] = 'instance-secret-B';
		$this->userSession->method('getUser')->willReturn($this->user('signer1', 'Database'));

		$response = $this->controller->recoverComplete();

		self::assertSame(self::INDEX_PAGE, $response->getRedirectURL());
		$this->assertCookieCleared($response);
	}

	public function testCompleteNeverReadsQueryParameters(): void {
		$this->incomingCookie = $this->validCookieFor('signer1');
		$this->userSession->method('getUser')->willReturn($this->user('signer1', 'Database'));
		$this->request->expects(self::never())->method('getParam');

		$this->controller->recoverComplete();
	}

	// ---- redirect sanitizer (reached through recoverComplete) -------------------

	/**
	 * [candidate from the URL generator, base URL, webroot, accepted as is?]
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
	 */
	public static function redirectProvider(): array {
		$u = self::UUID;
		$h = 'https://nc.example.com';

		return [
			// accepted
			'plain app path' => ["/apps/libresign/p/sign/$u", $h, '', true],
			'index.php app path' => ["/index.php/apps/libresign/p/sign/$u", $h, '', true],
			'subfolder app path' => ["/nextcloud/apps/libresign/p/sign/$u", $h . '/nextcloud', '/nextcloud', true],
			'subfolder index.php app path' => ["/nextcloud/index.php/apps/libresign/p/sign/$u", $h . '/nextcloud', '/nextcloud', true],
			'absolute same origin' => ["$h/apps/libresign/p/sign/$u", $h, '', true],
			'absolute same origin, explicit default port' => ["$h:443/apps/libresign/p/sign/$u", $h, '', true],
			'absolute same origin, scheme and host case' => ["HTTPS://NC.EXAMPLE.COM/index.php/apps/libresign/p/sign/$u", $h, '', true],
			'absolute same origin, subfolder' => ["$h/nextcloud/index.php/apps/libresign/p/sign/$u", $h . '/nextcloud', '/nextcloud', true],
			'absolute with a non-default port' => ["$h:8443/apps/libresign/p/sign/$u", $h . ':8443', '', true],
			'http base with explicit port 80' => ["http://nc.local:80/apps/libresign/p/sign/$u", 'http://nc.local', '', true],
			'query is kept' => ["/apps/libresign/p/sign/$u?x=1", $h, '', true],
			// rejected
			'other host' => ["https://evil.example/apps/libresign/p/sign/$u", $h, '', false],
			'other scheme' => ["http://nc.example.com/apps/libresign/p/sign/$u", $h, '', false],
			'other port' => ["$h:8443/apps/libresign/p/sign/$u", $h, '', false],
			'protocol relative' => ["//evil.example/apps/libresign/p/sign/$u", $h, '', false],
			'userinfo, own host as the user' => ["https://nc.example.com@evil.example/apps/libresign/p/sign/$u", $h, '', false],
			'userinfo, own host as the host' => ["https://evil.example@nc.example.com/apps/libresign/p/sign/$u", $h, '', false],
			'own host as a prefix of the evil host' => ["https://nc.example.com.evil.example/apps/libresign/p/sign/$u", $h, '', false],
			'javascript scheme' => ['javascript:alert(1)', $h, '', false],
			'scheme without host' => ["https:/apps/libresign/p/sign/$u", $h, '', false],
			'no leading slash' => ["apps/libresign/p/sign/$u", $h, '', false],
			'prefix lookalike' => ['/apps/libresign.evil/x', $h, '', false],
			'another app' => ['/apps/files/', $h, '', false],
			'another app under index.php' => ['/index.php/apps/files/', $h, '', false],
			'dot segments' => ['/apps/libresign/../files/x', $h, '', false],
			'encoded dot segments' => ['/apps/libresign/%2e%2e/files/x', $h, '', false],
			'upper-case encoded dot segments' => ['/apps/libresign/%2E%2E/files/x', $h, '', false],
			'encoded slash after the prefix' => ['/apps/libresign%2F..%2Ffiles', $h, '', false],
			'crlf' => ["/apps/libresign/x\r\nSet-Cookie: a=b", $h, '', false],
			'backslash' => ['/apps/libresign/x\\evil', $h, '', false],
			'encoded backslash' => ['/apps/libresign/%5cevil', $h, '', false],
			'encoded nul' => ['/apps/libresign/%00', $h, '', false],
			'raw nul' => ["/apps/libresign/\0", $h, '', false],
			'missing the subfolder' => ["/apps/libresign/p/sign/$u", $h . '/nextcloud', '/nextcloud', false],
			'another subfolder' => ["/other/apps/libresign/p/sign/$u", $h . '/nextcloud', '/nextcloud', false],
			'too long' => ['/apps/libresign/' . str_repeat('a', 2100), $h, '', false],
			'empty' => ['', $h, '', false],
		];
	}

	#[DataProvider('redirectProvider')]
	public function testRedirectIsKeptInsideTheApp(string $candidate, string $baseUrl, string $webroot, bool $accepted): void {
		$this->signUrl = $candidate;
		$this->baseUrl = $baseUrl;
		$this->webroot = $webroot;
		$this->incomingCookie = $this->validCookieFor('signer1');
		$this->userSession->method('getUser')->willReturn($this->user('signer1', 'Database'));

		$response = $this->controller->recoverComplete();

		self::assertSame($accepted ? $candidate : self::INDEX_PAGE, $response->getRedirectURL());
	}
}
