<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IServerContainer;
use OCP\ISession;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class SecurySignService {
	public function __construct(
		private IAppConfig $config,
		private IConfig $systemConfig,
		private ISession $session,
		private IUserSession $users,
		private IServerContainer $container,
		private IClientService $http,
		private LoggerInterface $logger,
	) {
	}

	public function providerId(): int {
		return $this->config->getValueInt(Application::APP_ID, 'securysign_provider_id', 0);
	}

	public function applies(): bool {
		$provider = $this->providerId();
		return $provider > 0 && $this->users->isLoggedIn()
			&& (int)$this->session->get('oidc.providerid') === $provider;
	}

	/** @return array{sub: string, issuer: string, accessToken: string} */
	public function identity(): array {
		if (!$this->applies()) {
			throw new \RuntimeException('A SecurySign OIDC session is required.', 401);
		}
		$tokens = $this->container->get('OCA\\UserOIDC\\Service\\TokenService');
		$token = $tokens->getToken();
		if ($token === null || $token->isExpired()
			|| $token->getProviderId() !== (int)$this->session->get('oidc.providerid')) {
			throw new \RuntimeException('Sign in again to connect to SecurySign. Enable user_oidc store_login_token if this persists.', 401);
		}
		$claims = $tokens->decodeIdToken($token);
		$issuer = $this->config->getValueString(Application::APP_ID, 'securysign_issuer');
		if ($issuer === '' || ($claims['iss'] ?? null) !== $issuer || empty($claims['sub'])) {
			throw new \RuntimeException('The SecurySign identity provider does not match this session.', 403);
		}
		return ['sub' => $claims['sub'], 'issuer' => $issuer, 'accessToken' => $token->getAccessToken()];
	}

	public function request(string $path, bool $allowMissing = false): ?array {
		$identity = $this->identity();
		$base = self::origin($this->config->getValueString(Application::APP_ID, 'securysign_url'));
		$response = $this->http->newClient()->get($base . '/api/' . $path, [
			'headers' => ['Authorization' => 'Bearer ' . $identity['accessToken'], 'Accept' => 'application/json'],
			'timeout' => 15,
			'allow_redirects' => false,
			'http_errors' => false,
		]);
		if ($allowMissing && $response->getStatusCode() === 404) {
			return null;
		}
		// The upstream status is the whole diagnosis, and a 503 that omits it sends
		// whoever reads the log back to the browser for another round trip. Only the
		// log sees this text: callers replace it before it reaches a user.
		$status = $response->getStatusCode();
		if ($status !== 200) {
			// Signa's own reason lives in the body, and without it a 401 is
			// indistinguishable from an expired token, a rejected audience or a
			// misconfigured introspection client. Logged, never shown.
			$this->logger->error('SecurySign refused a request', [
				'status' => $status,
				'path' => $path,
				'body' => substr((string)$response->getBody(), 0, 500),
				'tokenClaims' => self::claimNames($identity['accessToken']),
			]);
		}
		if ($status === 401 || $status === 403) {
			throw new \RuntimeException('SecurySign rejected your session (HTTP ' . $status . '). Sign out and in again to reconnect.', 401);
		}
		if ($status !== 200) {
			throw new \RuntimeException('SecurySign answered HTTP ' . $status . ' for /api/' . $path . '. Please retry.', 503);
		}
		$data = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
		if (!is_array($data)) {
			throw new \RuntimeException('SecurySign returned an invalid response.', 503);
		}
		return $data;
	}
	/**
	 * Whether SecurySign holds a certificate this user can sign with.
	 *
	 * `none` is the ordinary answer for someone who has never enrolled and means
	 * "send them to onboarding". Anything else unexpected is an outage and
	 * throws, because silently treating a broken response as "not ready" would
	 * march a paid-up user back through payment.
	 */
	public function isReady(): bool {
		$certificate = $this->request('pki/certificates/me');
		if (($certificate['status'] ?? null) === 'none') {
			return false;
		}
		if (($certificate['status'] ?? null) !== 'active' || !is_array($certificate['certificate'] ?? null)) {
			throw new \RuntimeException('SecurySign returned an unknown certificate status.', 503);
		}
		$leaf = $certificate['certificate'];
		$parsed = openssl_x509_parse($leaf['certificatePem'] ?? '');
		if ($parsed === false || empty($certificate['credentialId']) || empty($leaf['certificateId'])) {
			throw new \RuntimeException('SecurySign returned an invalid certificate.', 503);
		}
		return self::isCurrent($parsed);
	}

	/**
	 * Where a user without a usable certificate is sent.
	 *
	 * Read from `occ config:app:set libresign tendaworld_url` first, then from
	 * the system value, which Nextcloud also fills from an `NC_tendaworld_url`
	 * environment variable. A deployment that only sets env therefore needs no
	 * occ run, and one that ran occ is not overridden by the image's env.
	 */
	public function onboardingUrl(): string {
		$configured = $this->config->getValueString(Application::APP_ID, 'tendaworld_url')
			?: $this->systemConfig->getSystemValueString('tendaworld_url', 'https://tendaworld.com');
		return self::origin($configured) . '/onboarding/gopaperless';
	}


	/**
	 * Claim NAMES only. The values are the user's identity and must not be logged.
	 * Which names the access token carries is the whole diagnosis when Signa says
	 * a token is missing a claim, and it cannot be read any other way once the
	 * token is sealed in the session.
	 */
	public static function claimNames(string $jwt): string {
		$parts = explode('.', $jwt);
		if (count($parts) !== 3) {
			return 'opaque token, ' . count($parts) . ' segment(s)';
		}
		$payload = strtr($parts[1], '-_', '+/');
		$payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
		$decoded = base64_decode($payload, true);
		$claims = $decoded === false ? null : json_decode($decoded, true);
		return is_array($claims) ? implode(' ', array_keys($claims)) : 'unreadable payload';
	}


	/** @param array<string, mixed> $parsed openssl_x509_parse() output */
	public static function isCurrent(array $parsed): bool {
		$from = $parsed['validFrom_time_t'] ?? null;
		$to = $parsed['validTo_time_t'] ?? null;
		return is_int($from) && is_int($to) && $from <= time() && $to > time();
	}

	/**
	 * HTTPS everywhere except loopback, where a local development stack has no
	 * certificate. The carve-out is the same one browsers and RFC 8252 make: a
	 * loopback address cannot be reached off the machine, so plaintext there
	 * exposes nothing to the network.
	 */
	public static function origin(string $url): string {
		$parts = parse_url($url);
		$host = $parts['host'] ?? '';
		$scheme = $parts['scheme'] ?? '';
		$allowed = $scheme === 'https'
			|| ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true));
		if (!$parts || !$allowed || $host === ''
			|| isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
			|| !in_array($parts['path'] ?? '', ['', '/'], true)) {
			throw new \RuntimeException('Configure a valid HTTPS origin for the signing integration.', 503);
		}
		return rtrim($url, '/');
	}

	public static function returnPath(?string $path): string {
		if ($path === null || strlen($path) > 2048 || preg_match('/[\\\\\x00-\x20]/', $path)) {
			return '/apps/libresign/';
		}
		$decoded = rawurldecode(explode('?', $path, 2)[0]);
		if (!str_starts_with($decoded, '/apps/libresign/') || preg_match('~(?:^|/)\.{1,2}(?:/|$)|[\\\\\x00-\x20%]~', $decoded)
			|| preg_match('~^/apps/libresign/(?:sso|securysign)(?:/|$)~', $decoded)) {
			return '/apps/libresign/';
		}
		return $path;
	}
}
