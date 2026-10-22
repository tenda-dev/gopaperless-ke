<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SecurySignPdcGrantTest extends TestCase {
	private const TOKEN_ENDPOINT = 'https://idp.test/realms/signa/protocol/openid-connect/token';

	/** @var list<array{url: string, options: array<string, mixed>}> */
	private array $posts = [];

	/** A signed-in SecurySign user; Keycloak and Signa answer with these statuses. */
	private function service(int $exchangeStatus, int $grantStatus): SecurySignService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn(1);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $key === 'securysign_url' ? 'https://signa.test' : $default,
		);
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturnCallback(static fn (string $key) => $key === 'oidc.providerid' ? 1 : null);
		$users = $this->createMock(IUserSession::class);
		$users->method('isLoggedIn')->willReturn(true);

		$tokens = new class {
			public function getToken(): object {
				return new class {
					public function isExpired(): bool {
						return false;
					}
					public function getProviderId(): int {
						return 1;
					}
					public function getAccessToken(): string {
						return 'login-token';
					}
				};
			}
			public function decodeIdToken(object $token): array {
				return ['iss' => 'https://idp.test/realms/signa', 'sub' => 'google-1'];
			}
		};
		$providers = new class {
			public function getProvider(int $id): object {
				return new class {
					public function getDiscoveryEndpoint(): string {
						return 'https://idp.test/realms/signa/.well-known/openid-configuration';
					}
					public function getClientId(): string {
						return 'signa-rp-test';
					}
					public function getClientSecret(): string {
						return 'encrypted-secret';
					}
				};
			}
		};
		$discovery = new class {
			public function obtainDiscovery(object $provider): array {
				return ['token_endpoint' => 'https://idp.test/realms/signa/protocol/openid-connect/token'];
			}
		};
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturn('client-secret');
		$container = $this->createMock(IServerContainer::class);
		$container->method('get')->willReturnCallback(static fn (string $id) => match (true) {
			str_ends_with($id, 'ProviderMapper') => $providers,
			str_ends_with($id, 'DiscoveryService') => $discovery,
			$id === ICrypto::class => $crypto,
			default => $tokens,
		});

		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(function (string $url, array $options) use ($exchangeStatus, $grantStatus): IResponse {
			$this->posts[] = ['url' => $url, 'options' => $options];
			$exchange = $url === self::TOKEN_ENDPOINT;
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($exchange ? $exchangeStatus : $grantStatus);
			$response->method('getBody')->willReturn($exchange && $exchangeStatus === 200 ? '{"access_token":"exchanged"}' : '{"error":"no"}');
			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new SecurySignService($config, $this->createMock(IConfig::class), $session, $users, $container, $clients, $this->createMock(LoggerInterface::class));
	}

	public function testTheGrantCarriesAKeycloakVouchedTokenAndNoPrice(): void {
		$this->service(200, 200)->grantPdc('gp-pdc-attempt-1');

		self::assertCount(2, $this->posts);
		[$exchange, $grant] = $this->posts;
		self::assertSame(self::TOKEN_ENDPOINT, $exchange['url']);
		self::assertSame([
			'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
			'client_id' => 'signa-rp-test',
			'client_secret' => 'client-secret',
			'subject_token' => 'login-token',
			'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
			'requested_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
			'audience' => 'signa-entitlement-gateway',
			'scope' => 'entitlement:grant',
		], $exchange['options']['form_params']);

		self::assertSame('https://signa.test/api/rp/entitlements/grant', $grant['url']);
		self::assertSame('Bearer exchanged', $grant['options']['headers']['Authorization']);
		// Who the user is comes from the token; what GoPaperless charged never leaves.
		self::assertSame(['product' => 'pdc', 'units' => 1, 'idempotencyKey' => 'gp-pdc-attempt-1'], $grant['options']['json']);
	}

	public function testARefusedGrantIsAnOutageNotADeadSession(): void {
		try {
			$this->service(200, 403)->grantPdc('gp-pdc-attempt-1');
			self::fail('a 403 grant was accepted');
		} catch (\RuntimeException $e) {
			// 401 or 403 would sign the user out (SecurySignController::bail()).
			self::assertSame(503, $e->getCode());
		}
	}

	public function testNoGrantIsSentWhenKeycloakRefusesTheExchange(): void {
		try {
			$this->service(400, 200)->grantPdc('gp-pdc-attempt-1');
			self::fail('a refused exchange was accepted');
		} catch (\RuntimeException $e) {
			self::assertSame(503, $e->getCode());
		}
		self::assertCount(1, $this->posts);
	}
}
