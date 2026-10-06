<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\SecurySignService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class SecurySignController extends Controller {
	public function __construct(
		IRequest $request,
		private SecurySignService $signa,
		private ISession $session,
		private IUserSession $users,
		private IURLGenerator $urls,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'GET', url: '/securysign/onboard')]
	public function onboard(?string $returnTo = null): RedirectResponse {
		$path = SecurySignService::returnPath($returnTo);
		if (!$this->signa->applies()) {
			return new RedirectResponse($path);
		}
		try {
			$identity = $this->signa->identity();
			if ($this->signa->isReady(true)) {
				return new RedirectResponse($path);
			}
			$state = bin2hex(random_bytes(32));
			$this->session->set('libresign.securysign.onboarding', [
				'state' => $state, 'sub' => $identity['sub'], 'uid' => $this->users->getUser()->getUID(),
				'returnTo' => $path, 'expires' => time() + 3600,
			]);
			// MIMI sets up this account (it switches if signed in as another one,
			// asking for this email), then sends the user to our return route.
			return new RedirectResponse($this->signa->onboardingUrl() . '?' . http_build_query([
				'sub' => $identity['sub'],
				'email' => (string)$this->users->getUser()?->getEMailAddress(),
				'returnTo' => $this->urls->linkToRouteAbsolute('libresign.securySign.complete', ['state' => $state]),
			]));
		} catch (\Throwable $e) {
			return $this->bail($e, $path);
		}
	}

	/**
	 * MIMI sends the user here when setup finishes, and when they log out there.
	 * Anything short of a finished setup is discarded: the user signs in again and
	 * the gate sends them back to MIMI if they are still not set up.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'GET', url: '/securysign/return')]
	public function complete(string $state = ''): RedirectResponse {
		$pending = $this->session->get('libresign.securysign.onboarding');
		if (!is_array($pending) || $pending['expires'] < time() || !hash_equals($pending['state'], $state)
			|| $pending['uid'] !== $this->users->getUser()?->getUID()) {
			// A stale or foreign link. It must not sign anyone out, so the gate on
			// the home page decides where this user goes next.
			return new RedirectResponse(SecurySignService::returnPath(null));
		}
		$this->session->remove('libresign.securysign.onboarding');
		$path = SecurySignService::returnPath($pending['returnTo']);
		try {
			if ($pending['sub'] !== $this->signa->identity()['sub'] || !$this->signa->isReady(true)) {
				return $this->signOut();
			}
			return new RedirectResponse($path);
		} catch (\Throwable $e) {
			return $this->bail($e, $path);
		}
	}

	/**
	 * The signed-in user's signing card for the editor's preview: handwriting,
	 * verified name and issuer. The time is set when they sign. Null when
	 * SecurySign has no card for them, so the preview shows placeholders.
	 */
	#[NoAdminRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'GET', url: '/securysign/card')]
	public function card(): DataResponse {
		$context = null;
		if ($this->signa->applies()) {
			try {
				$context = $this->signa->signingContext();
			} catch (\Throwable $e) {
				$this->logger->info('No signing card for the preview', ['exception' => $e]);
			}
		}
		return new DataResponse(['card' => $context === null ? null : [
			'name' => $context['name'],
			'issuer' => $context['issuer'],
			'handwriting' => 'data:image/png;base64,' . base64_encode($context['handwriting']),
		]]);
	}

	/**
	 * A dead session means signing in again. Anything else is an outage, which the
	 * user should not notice: the page loads and the local engine signs.
	 */
	private function bail(\Throwable $e, string $path): RedirectResponse {
		$this->logger->error('SecurySign onboarding handoff failed', ['exception' => $e]);
		if (in_array($e->getCode(), [401, 403], true)) {
			return $this->signOut();
		}
		// A cached "not ready" would send the page straight back here.
		$this->signa->forgetReadiness();
		return new RedirectResponse($path);
	}

	private function signOut(): RedirectResponse {
		$this->users->logout();
		return new RedirectResponse($this->urls->linkToRoute('core.login.showLoginForm'));
	}
}
