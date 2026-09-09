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
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
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
	public function onboard(?string $returnTo = null): RedirectResponse|TemplateResponse {
		$path = SecurySignService::returnPath($returnTo);
		if (!$this->signa->applies()) {
			return new RedirectResponse($path);
		}
		try {
			$identity = $this->signa->identity();
			if ($this->signa->isReady()) {
				return new RedirectResponse($path);
			}
			$state = bin2hex(random_bytes(32));
			$this->session->set('libresign.securysign.onboarding', [
				'state' => $state, 'sub' => $identity['sub'], 'uid' => $this->users->getUser()->getUID(),
				'returnTo' => $path, 'expires' => time() + 3600,
			]);
			return new RedirectResponse($this->signa->onboardingUrl() . '?' . http_build_query([
				'state' => $state, 'subject' => $identity['sub'],
			]));
		} catch (\Throwable $e) {
			$this->logger->error('SecurySign onboarding handoff failed', ['exception' => $e]);
			return $this->failure();
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'GET', url: '/securysign/return')]
	public function complete(string $state = ''): RedirectResponse|TemplateResponse {
		$pending = $this->session->get('libresign.securysign.onboarding');
		try {
			if (!is_array($pending) || $pending['expires'] < time() || !hash_equals($pending['state'], $state)
				|| $pending['uid'] !== $this->users->getUser()?->getUID()
				|| $pending['sub'] !== $this->signa->identity()['sub']) {
				return $this->failure();
			}
			if (!$this->signa->isReady()) {
				return $this->failure();
			}
			$this->session->remove('libresign.securysign.onboarding');
			return new RedirectResponse(SecurySignService::returnPath($pending['returnTo']));
		} catch (\Throwable $e) {
			$this->logger->error('SecurySign onboarding handoff failed', ['exception' => $e]);
			return $this->failure();
		}
	}

	/**
	 * Same guest-layout page the middleware uses, so a user bounced out of the
	 * onboarding round trip sees the branding and the links rather than a wall of
	 * plain text on white.
	 */
	private function failure(): TemplateResponse {
		$response = new TemplateResponse(Application::APP_ID, 'securysign_notice', [
			'title' => 'We could not finish your setup',
			'message' => 'Something interrupted the connection to SecurySign. Any payment you have made is retained, and starting again picks up where you left off.',
			'actionLabel' => 'Start again',
			'actionUrl' => '/apps/libresign/',
			'secondaryLabel' => 'Back to GoPaperless',
			'secondaryUrl' => $this->urls->linkToDefaultPageUrl(),
		], TemplateResponse::RENDER_AS_GUEST);
		$response->setStatus(503);
		$response->cacheFor(0);
		return $response;
	}
}
