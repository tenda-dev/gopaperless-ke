<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\IdentifyMethodMapper;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Enum\RecoveryPlan;
use OCA\Libresign\Helper\RecoveryCookie;
use OCA\Libresign\Service\AccountRecovery\AccountRecoveryPlanner;
use OCA\Libresign\Service\IdentifyMethodService;
use OCA\Libresign\Service\AccountRecovery\RecoveryProviderVerifier;
use OCA\Libresign\Service\WhatsAppService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Handles recovery when a user opens a signing link while authenticated
 * as a different account.
 *
 * Recovery is navigation-only: the signed cookie identifies the intended
 * signing destination but never grants permission to sign. The existing
 * signing authorization gate remains authoritative.
 */
class AccountRecoveryController extends Controller {
	private const UUID_PATTERN = '/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD';
	private const CONTROL_OR_BACKSLASH = '/[\x00-\x1f\x7f\\\\]/';
	private const MAX_REDIRECT_LENGTH = 2048;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IUserManager $userManager,
		private IURLGenerator $urlGenerator,
		private IAppConfig $appConfig,
		private SignRequestMapper $signRequestMapper,
		private IdentifyMethodMapper $identifyMethodMapper,
		private RecoveryProviderVerifier $providerVerifier,
		private RecoveryCookie $recoveryCookie,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'POST', url: '/account/switch')]
	public function switchAccount(string $uuid = ''): RedirectResponse {
		$isUuid = preg_match(self::UUID_PATTERN, $uuid) === 1;
		$signPage = $isUuid
			? $this->urlGenerator->linkToRoute('libresign.page.sign', ['uuid' => $uuid])
			: $this->urlGenerator->linkToRoute('libresign.page.index');
		$plain = new RedirectResponse($signPage);

		$current = $this->userSession->getUser();
		// WhatsApp owns its existing authentication flow; do not expose the
		// intended account or initiate server-side recovery here.
		if (!$isUuid
			|| !$current instanceof IUser
			|| WhatsAppService::on($this->appConfig)
		) {
			return $plain;
		}

		$signer = $this->resolveIntendedSigner($uuid);
		if ($signer === null || $signer->getUID() === $current->getUID()) {
			return $plain;
		}

		$provider = $this->providerVerifier->verifiedId();
		$plan = AccountRecoveryPlanner::plan($current, $signer, $provider);
		if ($plan === RecoveryPlan::CLIENT_SLS) {
			// CLIENT_SLS performs its existing single-logout flow on the signing page;
			// server-side recovery must not issue a cookie or log out the session here.
			return $plain;
		}

		$cookie = $this->recoveryCookie->issue($uuid, $signer->getUID());
		if ($cookie === null) {
			// Cannot leave a trustworthy way back, so do not log anyone out.
			return $plain;
		}

		$complete = $this->urlGenerator->linkToRoute('libresign.accountRecovery.recoverComplete');
		$target = match ($plan) {
			RecoveryPlan::SERVER_OIDC => $this->urlGenerator->linkToRoute('user_oidc.login.login', [
				'providerId' => $provider,
				'redirectUrl' => $complete,
			]),
			RecoveryPlan::SERVER_NATIVE => $this->urlGenerator->linkToRoute('core.login.showLoginForm', [
				'direct' => 1,
				'redirect_url' => $complete,
			]),
			RecoveryPlan::SERVER_LOGIN => $this->urlGenerator->linkToRoute('core.login.showLoginForm', [
				'redirect_url' => $complete,
			]),
		};

		$response = new RedirectResponse($target);
		// SameSite=Lax allows the recovery cookie to accompany the top-level
		// navigation back from the identity provider.
		$response->addCookie(
			RecoveryCookie::NAME,
			$cookie,
			(new \DateTime())->setTimestamp(time() + RecoveryCookie::TTL),
			'Lax',
		);
		$this->userSession->logout();

		return $response;
	}

	/**
	 * Completes account recovery after authentication.
	 *
	 * Only the account bound to the signed recovery cookie may return to
	 * its signing destination. The cookie is cleared on every outcome, and
	 * the signing page's existing authorization gate remains authoritative.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'GET', url: '/account/recover/complete')]
	public function recoverComplete(): RedirectResponse {
		$rawCookie = $this->request->getCookie(RecoveryCookie::NAME);

		// Verify the navigation token before reading its destination or account binding.
		$verified = $this->recoveryCookie->verify($rawCookie);

		if ($verified === null) {
			$this->logger->warning('Account recovery rejected: recovery cookie is invalid or expired.', [
				'app' => Application::APP_ID,
				'reason' => 'invalid_or_expired_cookie',
			]);

			// Recovery is single-use; the signing page will independently recheck
			// whether the authenticated account is authorized to sign.
			$response = new RedirectResponse(
				$this->urlGenerator->linkToRoute('libresign.page.index')
			);
			$response->invalidateCookie(RecoveryCookie::NAME);

			return $response;
		}

		$current = $this->userSession->getUser();

		// Authentication alone is insufficient; the session must belong to the
		// account bound to the recovery cookie.
		$uidMatches = $current instanceof IUser
			&& $this->recoveryCookie->isBoundTo($verified, $current->getUID());

		if (!$uidMatches) {
			$this->logger->warning('Account recovery rejected: authenticated account does not match the recovery destination.', [
				'app' => Application::APP_ID,
				'reason' => 'account_binding_mismatch',
			]);
			$response = new RedirectResponse(
				$this->urlGenerator->linkToRoute('libresign.page.index')
			);
			$response->invalidateCookie(RecoveryCookie::NAME);

			return $response;
		}

		$target = $this->safeRedirectUrl(
			$this->urlGenerator->linkToRoute('libresign.page.sign', [
				'uuid' => $verified['uuid'],
			])
		);

		$response = new RedirectResponse($target);
		$response->invalidateCookie(RecoveryCookie::NAME);

		return $response;
	}

	/**
	 * Resolves by UID first, then by a single email match.
	 * Ambiguous email matches remain unresolved: recovery must not log the
	 * current user out based on a guess about the intended account.
	 */
	private function resolveIntendedSigner(string $uuid): ?IUser {
		try {
			$signRequest = $this->signRequestMapper->getByUuid($uuid);
		} catch (DoesNotExistException) {
			return null;
		}

		foreach ($this->identifyMethodMapper->getIdentifyMethodsFromSignRequestId($signRequest->getId()) as $method) {
			if ($method->getIdentifierKey() !== IdentifyMethodService::IDENTIFY_ACCOUNT) {
				continue;
			}
			$identifier = (string)$method->getIdentifierValue();
			$user = $this->userManager->get($identifier);
			if ($user instanceof IUser) {
				return $user;
			}
			$byEmail = $this->userManager->getByEmail($identifier);

			return count($byEmail) === 1 ? current($byEmail) : null;
		}

		return null;
	}

	/**
	 * Keeps a redirect inside this Nextcloud's LibreSign app. Relative app paths
	 * and absolute URLs with the instance's own scheme, host and port are
	 * accepted, with or without index.php and under a subfolder webroot;
	 * everything else falls back to the app root.
	 */
	private function safeRedirectUrl(string $candidate): string {
		// Reject ambiguous URL forms before browser normalization can change
		// the intended origin or path.
		if ($candidate === ''
			|| strlen($candidate) > self::MAX_REDIRECT_LENGTH
			|| preg_match(self::CONTROL_OR_BACKSLASH, $candidate) === 1
			|| str_starts_with($candidate, '//')
		) {
			return $this->urlGenerator->linkToRoute('libresign.page.index');
		}

		$parts = parse_url($candidate);
		if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
			return $this->urlGenerator->linkToRoute('libresign.page.index');
		}
		if (isset($parts['scheme']) || isset($parts['host'])) {
			if (!$this->isOwnOrigin($parts)) {
				return $this->urlGenerator->linkToRoute('libresign.page.index');
			}
		} elseif ($candidate[0] !== '/') {
			return $this->urlGenerator->linkToRoute('libresign.page.index');
		}

		return $this->isAppPath($parts['path'] ?? '')
			? $candidate
			: $this->urlGenerator->linkToRoute('libresign.page.index');
	}

	/** @param array<string, string|int> $parts parse_url() of the candidate */
	private function isOwnOrigin(array $parts): bool {
		$base = parse_url($this->urlGenerator->getBaseUrl());
		if (!is_array($base) || !isset($base['scheme'], $base['host'], $parts['scheme'], $parts['host'])) {
			return false;
		}
		$scheme = strtolower((string)$parts['scheme']);
		if (!in_array($scheme, ['http', 'https'], true) || $scheme !== strtolower((string)$base['scheme'])) {
			return false;
		}
		$defaultPort = $scheme === 'https' ? 443 : 80;

		return strtolower((string)$parts['host']) === strtolower((string)$base['host'])
			&& (int)($parts['port'] ?? $defaultPort) === (int)($base['port'] ?? $defaultPort);
	}

	private function isAppPath(string $path): bool {
		$webroot = rtrim($this->urlGenerator->getWebroot(), '/');
		if ($webroot !== '') {
			if (!str_starts_with($path, $webroot . '/')) {
				return false;
			}
			$path = substr($path, strlen($webroot));
		}

		$decoded = rawurldecode($path);
		if (preg_match(self::CONTROL_OR_BACKSLASH, $decoded) === 1) {
			return false;
		}
		foreach (explode('/', $decoded) as $segment) {
			if ($segment === '.' || $segment === '..') {
				return false;
			}
		}

		$appPath = '#^(?:/index\.php)?/apps/libresign/#';

		// Check both forms so percent-encoded separators or traversal cannot
		// bypass the application-path restriction.
		return preg_match($appPath, $path) === 1 && preg_match($appPath, $decoded) === 1;
	}
}
