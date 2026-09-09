<?php

declare(strict_types=1);

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
	/** Bump to re-import every mirrored signature; 3 dropped the composed card. */
	public const CARD_VERSION = 3;

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

	public function isReady(): bool {
		return $this->readiness() !== null;
	}

	/**
	 * What SecurySign holds for this user, or null when they are not ready yet.
	 * Returns the card alongside the certificate id so a caller can both gate on
	 * readiness and mirror the signature without asking twice.
	 *
	 * @return array{certificateId: string, imagePngBase64: string, issuer: string, serial: string, validFrom: string, validUntil: string}|null
	 */
	public function readiness(): ?array {
		$certificate = $this->request('pki/certificates/me');
		if (($certificate['status'] ?? null) === 'none') {
			return null;
		}
		if (($certificate['status'] ?? null) !== 'active' || !is_array($certificate['certificate'] ?? null)) {
			throw new \RuntimeException('SecurySign returned an unknown certificate status.', 503);
		}
		$leaf = $certificate['certificate'];
		$parsed = openssl_x509_parse($leaf['certificatePem'] ?? '');
		if ($parsed === false || empty($certificate['credentialId']) || empty($leaf['certificateId'])) {
			throw new \RuntimeException('SecurySign returned an invalid certificate.', 503);
		}
		if (!self::isCurrent($parsed)) {
			return null;
		}
		$signature = $this->request('signature/visible', true);
		if ($signature === null) {
			return null;
		}
		if ((string)($signature['certificateId'] ?? '') !== (string)$leaf['certificateId']) {
			return null;
		}
		$image = base64_decode($signature['imagePngBase64'] ?? '', true);
		if (!is_string($image) || !str_starts_with($image, "\x89PNG\r\n\x1a\n")) {
			throw new \RuntimeException('SecurySign returned an invalid visible signature.', 503);
		}
		return [
			'certificateId' => (string)$leaf['certificateId'],
			'imagePngBase64' => (string)$signature['imagePngBase64'],
			'issuer' => (string)($leaf['issuer'] ?? 'SecurySign'),
			'serial' => (string)($leaf['serialNumberHex'] ?? ''),
			'validFrom' => self::day($leaf['validFrom'] ?? null),
			'validUntil' => self::day($leaf['validUntil'] ?? null),
		];
	}


	/**
	 * Mirror the SecurySign card into the user's LibreSign signature elements so
	 * it is what lands on a document. SecurySign owns it: users cannot draw their
	 * own (see SignerElementsService::canCreateSignature), so any element they
	 * have came from here, and one that names a superseded certificate is
	 * replaced after a renewal.
	 *
	 * Takes the readiness array rather than re-reading it, so a page load still
	 * costs one pair of calls to SecurySign. Failures are logged and swallowed:
	 * the card is already verified, and failing to cache it locally is no reason
	 * to lock someone out of the app.
	 *
	 * @param array{certificateId: string, imagePngBase64: string} $readiness
	 */
	public function syncVisibleSignature(array $readiness): void {
		$user = $this->users->getUser();
		if ($user === null) {
			return;
		}
		try {
			// Resolved lazily: AccountService reaches SignerElementsService, which
			// reaches this class, so constructor injection would not build.
			$mapper = $this->container->get(\OCA\Libresign\Db\UserElementMapper::class);
			$existing = $mapper->findMany(['user_id' => $user->getUID(), 'type' => 'signature']);
			// Both the certificate and the card layout have to match. Without the
			// version, changing the card design would leave every existing user on
			// the old image forever, because their certificate had not changed.
			foreach ($existing as $element) {
				$metadata = $element->getMetadata() ?? [];
				if (($metadata['securysign_certificate_id'] ?? null) === $readiness['certificateId']
					&& ($metadata['securysign_card'] ?? null) === self::CARD_VERSION) {
					return;
				}
			}

			// The handwriting is stored exactly as SecurySign returned it. The
			// account, issuer and validity details are added at signing time by
			// LibreSign's own signature text template, which already has variables
			// for every one of them — see the runbook. Compositing a card here
			// duplicated a feature the app ships.
			$accounts = $this->container->get(\OCA\Libresign\Service\AccountService::class);
			$accounts->saveVisibleElement([
				'type' => 'signature',
				'file' => ['base64' => 'data:image/png;base64,' . $readiness['imagePngBase64']],
				'starred' => 1,
			], '', $user);

			$stale = array_column(array_map(static fn ($e) => ['id' => $e->getId()], $existing), 'id');
			foreach ($mapper->findMany(['user_id' => $user->getUID(), 'type' => 'signature']) as $element) {
				if (in_array($element->getId(), $stale, true)) {
					$mapper->delete($element);
					continue;
				}
				$element->setMetadata(($element->getMetadata() ?? []) + [
					'securysign_certificate_id' => $readiness['certificateId'],
					'securysign_card' => self::CARD_VERSION,
				]);
				$mapper->update($element);
			}
			$this->logger->info('Imported the SecurySign visible signature', [
				'certificateId' => $readiness['certificateId'],
				'replaced' => count($stale),
			]);
		} catch (\Throwable $e) {
			$this->logger->error('Could not import the SecurySign visible signature', ['exception' => $e]);
		}
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


	/** Dates go on a card a person reads, so seconds and timezones are noise. */
	private static function day(?string $value): string {
		$time = $value === null ? false : strtotime($value);
		return $time === false ? '—' : date('j M Y', $time);
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
