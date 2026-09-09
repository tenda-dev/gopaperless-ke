<?php

declare(strict_types=1);

namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Service\SecurySignService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IServerContainer;
use OCP\ISession;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class SecurySignServiceTest extends TestCase {
	public function testReturnPathsCannotEscapeTheAppOrRestartAuthentication(): void {
		foreach ([null, '//evil.test', '/apps/libresign/../settings', '/apps/libresign/%2e%2e/settings', '/apps/libresign/%252e%252e/settings', '/apps/libresign/\\evil.test', '/apps/libresign/sso', '/apps/libresign/securysign/return', "https://evil.test", "/apps/libresign/f/\nfoo"] as $path) {
			self::assertSame('/apps/libresign/', SecurySignService::returnPath($path));
		}
		self::assertSame('/apps/libresign/f/document?tab=sign', SecurySignService::returnPath('/apps/libresign/f/document?tab=sign'));
	}

	public function testOnlyTheConfiguredOidcSessionIsIncluded(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn(2);
		$session = $this->createMock(ISession::class);
		$provider = null;
		$session->method('get')->willReturnCallback(static function () use (&$provider) { return $provider; });
		$users = $this->createMock(IUserSession::class);
		$users->method('isLoggedIn')->willReturn(true);
		$service = new SecurySignService($config, $this->createMock(IConfig::class), $session, $users, $this->createMock(IServerContainer::class), $this->createMock(IClientService::class), $this->createMock(LoggerInterface::class));
		self::assertFalse($service->applies());
		$provider = 1;
		self::assertFalse($service->applies());
		$provider = 2;
		self::assertTrue($service->applies());
	}

	public function testTheOnboardingTargetComesFromOccOrTheSystemValue(): void {
		$fromOcc = '';
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static function () use (&$fromOcc) { return $fromOcc; });
		$system = $this->createMock(IConfig::class);
		$system->method('getSystemValueString')->willReturn('https://staging.tendaworld.com');
		$service = new SecurySignService($appConfig, $system, $this->createMock(ISession::class), $this->createMock(IUserSession::class), $this->createMock(IServerContainer::class), $this->createMock(IClientService::class), $this->createMock(LoggerInterface::class));

		// Nobody ran occ: the system value, which an NC_tendaworld_url env var fills.
		self::assertSame('https://staging.tendaworld.com/onboarding/gopaperless', $service->onboardingUrl());

		// occ wins once it is set, so the image's env cannot override an admin.
		$fromOcc = 'https://tendaworld.com';
		self::assertSame('https://tendaworld.com/onboarding/gopaperless', $service->onboardingUrl());
	}

	public function testMissingCertificateIsDifferentFromAnOutage(): void {
		$service = $this->getMockBuilder(SecurySignService::class)->disableOriginalConstructor()->onlyMethods(['request'])->getMock();
		$service->method('request')->willReturn(['status' => 'none']);
		self::assertFalse($service->isReady());
		$service = $this->getMockBuilder(SecurySignService::class)->disableOriginalConstructor()->onlyMethods(['request'])->getMock();
		$service->method('request')->willReturn(['error' => 'unavailable']);
		$this->expectException(\RuntimeException::class);
		$service->isReady();
	}

	public function testACertificateOutsideItsValidityWindowIsNotUsable(): void {
		$now = time();
		self::assertTrue(SecurySignService::isCurrent(['validFrom_time_t' => $now - 10, 'validTo_time_t' => $now + 10]));
		self::assertFalse(SecurySignService::isCurrent(['validFrom_time_t' => $now - 20, 'validTo_time_t' => $now - 10]));
		self::assertFalse(SecurySignService::isCurrent(['validFrom_time_t' => $now + 10, 'validTo_time_t' => $now + 20]));
		self::assertFalse(SecurySignService::isCurrent([]));
		self::assertFalse(SecurySignService::isCurrent(['validFrom_time_t' => 'soon', 'validTo_time_t' => 'later']));
	}

	private static function selfSignedPem(): string {
		$key = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$csr = $key ? @openssl_csr_new(['commonName' => 'SecurySign Test'], $key, ['digest_alg' => 'sha256']) : false;
		$x509 = $csr ? @openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']) : false;
		if (!$x509 || !openssl_x509_export($x509, $pem)) {
			self::markTestSkipped('openssl cannot issue a certificate here; set OPENSSL_CONF to the runtime openssl.cnf');
		}
		return $pem;
	}

	public function testConfiguredOriginCannotIncludeCredentialsOrPaths(): void {
		self::assertSame('https://signa.test', SecurySignService::origin('https://signa.test/'));
		self::assertSame('http://localhost:3000', SecurySignService::origin('http://localhost:3000'));
		self::assertSame('http://127.0.0.1', SecurySignService::origin('http://127.0.0.1/'));
		foreach ([
			'http://signa.test', 'http://localhost.signa.test', 'http://127.0.0.1.signa.test',
			'https://user:password@signa.test', 'https://signa.test/api', 'https://signa.test?x=1',
			'https://signa.test#fragment', 'http://localhost/api',
		] as $url) {
			try {
				SecurySignService::origin($url);
				self::fail('Invalid origin accepted: ' . $url);
			} catch (\RuntimeException $e) {
				self::assertSame(503, $e->getCode());
			}
		}
	}
	/**
	 * @return array{0: SecurySignService, 1: object}
	 */
	private static function serviceAnswering(int $status, string $body = '{}'): SecurySignService {
		$test = new self('t');
		$config = $test->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn(1);
		$config->method('getValueString')->willReturnCallback(static function (string $app, string $key, string $default = '') {
			return $key === 'securysign_issuer' ? 'https://idp.test/realms/signa' : 'https://signa.test';
		});
		$session = $test->createMock(ISession::class);
		$session->method('get')->willReturn(1);
		$users = $test->createMock(IUserSession::class);
		$users->method('isLoggedIn')->willReturn(true);

		$token = new class {
			public function isExpired(): bool { return false; }
			public function getProviderId(): int { return 1; }
			public function getAccessToken(): string { return 'at'; }
		};
		$tokens = new class($token) {
			public function __construct(private object $token) {}
			public function getToken(): object { return $this->token; }
			public function decodeIdToken(object $t): array { return ['iss' => 'https://idp.test/realms/signa', 'sub' => 'google-1']; }
		};
		$container = $test->createMock(IServerContainer::class);
		$container->method('get')->willReturn($tokens);

		$response = $test->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		$client = $test->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$clients = $test->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new SecurySignService($config, $test->createMock(IConfig::class), $session, $users, $container, $clients, $test->createMock(LoggerInterface::class));
	}

	public function testARejectedSessionAsksForReauthAndAnOutageNamesTheStatus(): void {
		foreach ([401, 403] as $status) {
			try {
				self::serviceAnswering($status)->request('pki/certificates/me');
				self::fail('HTTP ' . $status . ' accepted');
			} catch (\RuntimeException $e) {
				self::assertSame(401, $e->getCode());
				self::assertStringContainsString((string)$status, $e->getMessage());
			}
		}

		try {
			self::serviceAnswering(500)->request('pki/certificates/me');
			self::fail('HTTP 500 accepted');
		} catch (\RuntimeException $e) {
			self::assertSame(503, $e->getCode());
			self::assertStringContainsString('500', $e->getMessage());
			self::assertStringContainsString('pki/certificates/me', $e->getMessage());
		}

		self::assertNull(self::serviceAnswering(404)->request('pki/certificates/me', true));
		self::assertSame(['status' => 'none'], self::serviceAnswering(200, '{"status":"none"}')->request('pki/certificates/me'));
	}
	public function testClaimNamesNeverLeakValues(): void {
		$payload = rtrim(strtr(base64_encode('{"sub":"google-1","email":"a@b.test"}'), '+/', '-_'), '=');
		$names = SecurySignService::claimNames('header.' . $payload . '.signature');
		self::assertSame('sub email', $names);
		self::assertStringNotContainsString('google-1', $names);
		self::assertStringNotContainsString('a@b.test', $names);

		self::assertStringContainsString('opaque', SecurySignService::claimNames('not-a-jwt'));
		self::assertStringContainsString('unreadable', SecurySignService::claimNames('a.!!!.c'));
	}
	public function testACurrentCertificateIsWhatMakesAUserReady(): void {
		$service = $this->getMockBuilder(SecurySignService::class)
			->disableOriginalConstructor()->onlyMethods(['request'])->getMock();
		$service->method('request')->willReturn([
			'status' => 'active',
			'credentialId' => 'c1',
			'certificate' => ['certificateId' => 7, 'certificatePem' => self::selfSignedPem()],
		]);

		self::assertTrue($service->isReady());
	}
}
