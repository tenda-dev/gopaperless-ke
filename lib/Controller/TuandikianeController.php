<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\SecurySignService;
use OCA\Libresign\Service\TuandikianeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * The sender's review page for a Tuandikiane draft, and the page that lets a
 * signer known only by phone take their signer slot. Both need a GoPaperless
 * session, from the normal login, and a MIMI passkey on the action itself.
 */
class TuandikianeController extends Controller {
	public function __construct(
		IRequest $request,
		private TuandikianeService $tuandikiane,
		private SecurySignService $signa,
		private IUserSession $userSession,
		private IUserManager $users,
		private IURLGenerator $urls,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[UseSession]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/p/t/review/{code}')]
	public function review(string $code): TemplateResponse {
		$draft = $this->tuandikiane->draftForCode($code);
		if ($draft === null) {
			return $this->page('expired', []);
		}
		$gate = $this->gate($this->urls->linkToRoute('libresign.tuandikiane.review', ['code' => $code]));
		if ($gate !== null) {
			return $gate;
		}
		// As the app's own pages do: the session's first readiness answer
		// remembers the sender's certificate, so "Sign now" can open ready.
		try {
			$this->signa->isReady();
		} catch (\Throwable) {
		}
		$review = $this->tuandikiane->review($draft);
		return $this->page('review', [
			'documentName' => $review['file']->getName(),
			'pages' => $review['pages'],
			'boxes' => $review['boxes'],
			'signers' => $review['signers'],
			'pdfUrl' => $this->urls->linkToRoute('libresign.tuandikiane.pdf', ['code' => $code]),
			'optionsUrl' => $this->urls->linkToRoute('libresign.tuandikiane.reviewOptions', ['code' => $code]),
			'actionUrl' => $this->urls->linkToRoute('libresign.tuandikiane.send', ['code' => $code]),
			'here' => $this->urls->linkToRouteAbsolute('libresign.tuandikiane.review', ['code' => $code]),
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/p/t/review/{code}/pdf')]
	public function pdf(string $code): FileDisplayResponse|DataResponse {
		$draft = $this->tuandikiane->draftForCode($code);
		if ($draft === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		try {
			$response = new FileDisplayResponse($this->tuandikiane->document($draft), Http::STATUS_OK, ['Content-Type' => 'application/pdf']);
		} catch (\Throwable $e) {
			$this->logger->warning('A Tuandikiane draft has no document', ['exception' => $e]);
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$response->cacheFor(0);
		return $response;
	}

	#[NoAdminRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'POST', url: '/p/t/review/{code}/options')]
	public function reviewOptions(string $code): DataResponse {
		if ($this->tuandikiane->draftForCode($code) === null) {
			return new DataResponse(['error' => 'expired'], Http::STATUS_GONE);
		}
		return $this->options('libresign.tuandikiane.review', $code);
	}

	/**
	 * Send, with the MIMI passkey's answer. Responds with the sender's own
	 * signing card, for "Sign now", or null when the sender doesn't sign.
	 */
	#[NoAdminRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/tuandikiane/review/{code}/send')]
	public function send(string $code, array $response = []): DataResponse {
		$user = $this->userSession->getUser();
		$draft = $this->tuandikiane->draftForCode($code);
		if (!$user instanceof IUser || $draft === null) {
			return new DataResponse(['error' => 'expired'], Http::STATUS_GONE);
		}
		$denied = $this->passkey($response);
		if ($denied !== null) {
			return $denied;
		}
		try {
			return new DataResponse(['signUrl' => $this->tuandikiane->send($draft, $user)]);
		} catch (LibresignException $e) {
			return new DataResponse(['error' => 'failed', 'message' => $e->getMessage()], $e->getCode() === Http::STATUS_GONE ? Http::STATUS_GONE : Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			$this->logger->error('A Tuandikiane draft could not be sent', ['exception' => $e]);
			return new DataResponse(['error' => 'failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Where the WhatsApp card sends a signer whose slot no account holds yet
	 * (WhatsAppSignController::page). Anyone else goes back to the card.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[UseSession]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/p/t/sign/{code}')]
	public function sign(string $code): TemplateResponse|RedirectResponse {
		$found = $this->tuandikiane->unboundSlot($code);
		if ($found === null) {
			return new RedirectResponse($this->urls->linkToRoute('libresign.whatsAppSign.page', ['code' => $code]));
		}
		$gate = $this->gate($this->urls->linkToRoute('libresign.tuandikiane.sign', ['code' => $code]));
		if ($gate !== null) {
			return $gate;
		}
		$review = $this->tuandikiane->review($found['draft']);
		$owner = $this->users->get($review['file']->getUserId());
		return $this->page('bind', [
			'documentName' => $review['file']->getName(),
			'senderName' => $owner?->getDisplayName() ?? '',
			'optionsUrl' => $this->urls->linkToRoute('libresign.tuandikiane.signOptions', ['code' => $code]),
			'actionUrl' => $this->urls->linkToRoute('libresign.tuandikiane.bind', ['code' => $code]),
			'here' => $this->urls->linkToRouteAbsolute('libresign.tuandikiane.sign', ['code' => $code]),
		]);
	}

	#[NoAdminRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'POST', url: '/p/t/sign/{code}/options')]
	public function signOptions(string $code): DataResponse {
		if ($this->tuandikiane->unboundSlot($code) === null) {
			return new DataResponse(['error' => 'expired'], Http::STATUS_GONE);
		}
		return $this->options('libresign.tuandikiane.sign', $code);
	}

	/** Take the slot, with the MIMI passkey's answer, then on to the normal card. */
	#[NoAdminRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'POST', url: '/p/t/sign/{code}/bind')]
	public function bind(string $code, array $response = []): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user instanceof IUser || $this->tuandikiane->unboundSlot($code) === null) {
			return new DataResponse(['error' => 'expired'], Http::STATUS_GONE);
		}
		$denied = $this->passkey($response);
		if ($denied !== null) {
			return $denied;
		}
		try {
			$this->tuandikiane->bindCard($code, $user);
		} catch (LibresignException $e) {
			return new DataResponse(['error' => 'failed', 'message' => $e->getMessage()], $e->getCode() === Http::STATUS_GONE ? Http::STATUS_GONE : Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			$this->logger->error('A Tuandikiane signer slot could not be bound', ['exception' => $e]);
			return new DataResponse(['error' => 'failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new DataResponse(['redirect' => $this->urls->linkToRoute('libresign.whatsAppSign.page', ['code' => $code])]);
	}

	/**
	 * WebAuthn options for the user's MIMI passkey, through the same server
	 * call as the check after sign-in. With no MIMI passkey yet, MIMI sets one
	 * up and sends them back to this page. Unlike that check, an outage does
	 * not let anyone through: nothing is sent without the passkey.
	 */
	private function options(string $route, string $code): DataResponse {
		try {
			$options = $this->signa->passkeyOptions();
			if ($options === null) {
				return new DataResponse(['redirect' => $this->signa->mimiEnrolmentUrl(
					$this->signa->identity()['sub'],
					(string)$this->userSession->getUser()?->getEMailAddress(),
					$this->urls->linkToRouteAbsolute($route, ['code' => $code]),
				)]);
			}
			return new DataResponse(['options' => $options]);
		} catch (\Throwable $e) {
			if (in_array($e->getCode(), [Http::STATUS_UNAUTHORIZED, Http::STATUS_FORBIDDEN], true)) {
				// Not a SecurySign session, or its token ran out: sign in again, then back here.
				$this->userSession->logout();
				return new DataResponse(['redirect' => $this->urls->linkToRoute('core.login.showLoginForm', [
					'redirect_url' => $this->urls->linkToRoute($route, ['code' => $code]),
				])]);
			}
			$this->logger->warning('MIMI passkey options unavailable for Tuandikiane', ['exception' => $e]);
			return new DataResponse(['error' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}

	/** Null when MIMI accepts the answer as this user's passkey, else the response to give. */
	private function passkey(array $response): ?DataResponse {
		try {
			if ($this->signa->verifyPasskey($response)) {
				return null;
			}
		} catch (\Throwable $e) {
			$this->logger->warning('MIMI passkey check unavailable for Tuandikiane', ['exception' => $e]);
			return new DataResponse(['error' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
		return new DataResponse([
			'error' => 'rejected',
			'message' => $this->l10n->t('That passkey could not be confirmed. Use the MIMI passkey for this account and try again.'),
		], Http::STATUS_FORBIDDEN);
	}

	/**
	 * Before either page: WhatsApp's own browser can't run a passkey, so it gets
	 * the WhatsApp card's hand-off to Chrome; anyone signed out goes to the
	 * normal login and back, after the same browser check /p/open-in-browser
	 * makes. Null when the page can show.
	 */
	private function gate(string $path): ?TemplateResponse {
		$userAgent = $this->request->getHeader('User-Agent');
		$inApp = WhatsAppSignController::isInAppBrowser($userAgent);
		if (!$inApp && $this->userSession->isLoggedIn()) {
			return null;
		}
		Util::addStyle(Application::APP_ID, 'libresign-login');
		Util::addScript(Application::APP_ID, 'libresign-login');
		Util::addScript(Application::APP_ID, 'libresign-whatsapp-sign');
		$response = new TemplateResponse(Application::APP_ID, 'whatsapp_sign', [
			'state' => $inApp ? 'browser' : 'checking',
			'documentName' => '',
			'signUrl' => '',
			'target' => $inApp ? '' : $this->urls->linkToRoute('core.login.showLoginForm', ['redirect_url' => $path]),
			'chromeLink' => WhatsAppSignController::chromeLink($userAgent, $this->urls->getAbsoluteURL($path)),
		], TemplateResponse::RENDER_AS_GUEST);
		$response->cacheFor(0);
		return $response;
	}

	private function page(string $mode, array $params): TemplateResponse {
		Util::addStyle(Application::APP_ID, 'libresign-login');
		Util::addScript(Application::APP_ID, 'libresign-login');
		$policy = new ContentSecurityPolicy();
		if ($mode !== 'expired') {
			Util::addScript(Application::APP_ID, 'libresign-tuandikiane');
			try {
				$frame = $this->signa->passkeyFrame($this->l10n->getLanguageCode());
				$policy->addAllowedFrameDomain($frame['origin']);
			} catch (\RuntimeException $e) {
				// No MIMI address: the page shows, and its button says MIMI isn't answering.
				$this->logger->warning('No MIMI passkey frame for Tuandikiane', ['exception' => $e]);
				$frame = ['origin' => '', 'url' => ''];
			}
			$params['frameUrl'] = $frame['url'];
			$userAgent = $this->request->getHeader('User-Agent');
			$params['chromeLink'] = WhatsAppSignController::chromeLink($userAgent, $params['here']);
			// pdf.js renders the pages in a worker served from this instance.
			$policy->addAllowedWorkerSrcDomain("'self'");
		}
		$response = new TemplateResponse(Application::APP_ID, 'tuandikiane', ['mode' => $mode] + $params, TemplateResponse::RENDER_AS_GUEST);
		$response->setContentSecurityPolicy($policy);
		$response->cacheFor(0);
		return $response;
	}
}
