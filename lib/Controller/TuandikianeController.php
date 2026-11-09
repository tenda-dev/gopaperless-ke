<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\TuandikianeDraft;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\Payment\LinkPaymentService;
use OCA\Libresign\Service\SecurySignService;
use OCA\Libresign\Service\TuandikianeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
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
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Seal & Deliver, the moderator's page for a Tuandikiane draft, and the
 * invite a joiner gets. No GoPaperless login on either: the link names the
 * draft, and the moderator's MIMI passkey (or their own signature, when they
 * sign) is what seals it.
 */
class TuandikianeController extends Controller {
	public function __construct(
		IRequest $request,
		private TuandikianeService $tuandikiane,
		private SecurySignService $signa,
		private LinkPaymentService $payments,
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
	public function review(string $code): TemplateResponse|RedirectResponse {
		$draft = $this->tuandikiane->draftForCode($code);
		if ($draft === null || ($draft->getStatus() === TuandikianeDraft::STATUS_DRAFT && !$this->tuandikiane->isOpen($draft))) {
			return $this->page('expired', []);
		}
		$here = $this->urls->linkToRouteAbsolute('libresign.tuandikiane.review', ['code' => $code]);
		$handOff = $this->handOff($here);
		if ($handOff !== null) {
			return $handOff;
		}
		$review = $this->tuandikiane->review($draft);
		$base = [
			'documentName' => $review['file']->getName(),
			'signers' => $review['signers'],
			'email' => (string)$review['moderator']->getEMailAddress(),
		];
		$card = $draft->getStatus() === TuandikianeDraft::STATUS_SEALED ? $this->tuandikiane->moderatorCard($draft) : null;
		if ($card !== null) {
			// Sealed by a moderator who signs, who hasn't yet: their signature is the seal.
			return new RedirectResponse($card);
		}
		if ($draft->getStatus() !== TuandikianeDraft::STATUS_DRAFT) {
			return $this->page('done', $base);
		}
		return $this->page('review', $base + [
			'pages' => $review['pages'],
			'boxes' => $review['boxes'],
			'money' => $review['money'],
			'phone' => $this->moderatorPhone($draft),
			'signs' => array_filter($review['signers'], static fn (array $s): bool => $s['sender']) !== [],
			'pdfUrl' => $this->urls->linkToRoute('libresign.tuandikiane.pdf', ['code' => $code]),
			'optionsUrl' => $this->urls->linkToRoute('libresign.tuandikiane.reviewOptions', ['code' => $code]),
			'actionUrl' => $this->urls->linkToRoute('libresign.tuandikiane.seal', ['code' => $code]),
			'payUrl' => $this->urls->linkToRoute('libresign.tuandikiane.pay', ['code' => $code]),
			'here' => $here,
		]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/p/t/review/{code}/pdf')]
	public function pdf(string $code): FileDisplayResponse|DataResponse {
		$draft = $this->tuandikiane->draftForCode($code);
		if ($draft === null || !$this->tuandikiane->isOpen($draft)) {
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

	/**
	 * WebAuthn options for the moderator's MIMI passkey. With none yet, MIMI
	 * sets one up and sends them back to this page. An outage lets nobody
	 * through: nothing is sealed without the passkey.
	 */
	#[PublicPage]
	#[UseSession]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/p/t/review/{code}/options')]
	public function reviewOptions(string $code): DataResponse {
		$draft = $this->tuandikiane->draftForCode($code);
		if ($draft === null || !$this->tuandikiane->isOpen($draft)) {
			return new DataResponse(['error' => 'expired'], Http::STATUS_GONE);
		}
		try {
			$sub = $this->tuandikiane->moderatorSub($draft) ?? throw new \RuntimeException('The moderator has no MIMI account.');
			$options = $this->signa->passkeyOptions($sub);
			if ($options === null) {
				return new DataResponse(['redirect' => $this->signa->mimiEnrolmentUrl(
					$sub,
					(string)$this->tuandikiane->moderator($draft)->getEMailAddress(),
					$this->urls->linkToRouteAbsolute('libresign.tuandikiane.review', ['code' => $code]),
				)]);
			}
			return new DataResponse(['options' => $options]);
		} catch (\Throwable $e) {
			$this->logger->warning('MIMI passkey options unavailable for Tuandikiane', ['exception' => $e]);
			return new DataResponse(['error' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}

	/**
	 * Seal & Deliver. A moderator who signs needs no passkey here: delivery
	 * waits for their signature on the card this answers with. Anyone else's
	 * seal is their MIMI passkey's answer.
	 */
	#[PublicPage]
	#[UseSession]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/p/t/review/{code}/seal')]
	public function seal(string $code, bool $payForSigners = true, array $response = []): DataResponse {
		$draft = $this->tuandikiane->draftForCode($code);
		if ($draft === null || !$this->tuandikiane->isOpen($draft)) {
			return new DataResponse(['error' => 'expired'], Http::STATUS_GONE);
		}
		$signs = array_filter($draft->getSlots() ?? [], static fn (array $slot): bool => $slot['sender']) !== [];
		if (!$signs) {
			$denied = $this->passkey($draft, $response);
			if ($denied !== null) {
				return $denied;
			}
		}
		try {
			return new DataResponse(['signUrl' => $this->tuandikiane->seal($draft, $payForSigners)]);
		} catch (LibresignException $e) {
			return match ($e->getCode()) {
				Http::STATUS_PAYMENT_REQUIRED => new DataResponse(['error' => 'payment_required', 'short' => $this->tuandikiane->shortfall($draft, $payForSigners)], Http::STATUS_PAYMENT_REQUIRED),
				Http::STATUS_GONE => new DataResponse(['error' => 'expired'], Http::STATUS_GONE),
				default => new DataResponse(['error' => 'failed', 'message' => $e->getMessage()], Http::STATUS_CONFLICT),
			};
		} catch (\Throwable $e) {
			$this->logger->error('A Tuandikiane draft could not be sealed', ['exception' => $e]);
			return new DataResponse(['error' => 'failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Buy what the moderator's credits fall short of, as the moderator. M-Pesa
	 * prompts their own number unless they give another.
	 */
	#[PublicPage]
	#[AnonRateLimit(limit: 6, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/p/t/review/{code}/pay')]
	public function pay(string $code, bool $payForSigners = true, string $method = 'mobile', string $phone = ''): DataResponse {
		$draft = $this->tuandikiane->draftForCode($code);
		if ($draft === null || !$this->tuandikiane->isOpen($draft)) {
			return new DataResponse(['error' => 'expired'], Http::STATUS_GONE);
		}
		$short = $this->tuandikiane->shortfall($draft, $payForSigners);
		if ($short === 0) {
			return new DataResponse(['status' => 'SUCCESS']);
		}
		try {
			return new DataResponse($this->payments->start(
				$this->tuandikiane->moderator($draft),
				$short,
				$method,
				$phone !== '' ? $phone : $this->moderatorPhone($draft),
				$this->urls->linkToRouteAbsolute('libresign.tuandikiane.review', ['code' => $code]),
			));
		} catch (\Throwable $e) {
			$this->logger->warning('A Tuandikiane payment did not start', ['exception' => $e]);
			return new DataResponse(['error' => 'failed'], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/p/t/review/{code}/pay/{reference}')]
	public function payStatus(string $code, string $reference): DataResponse {
		$draft = $this->tuandikiane->draftForCode($code);
		if ($draft === null) {
			return new DataResponse(['error' => 'expired'], Http::STATUS_GONE);
		}
		try {
			return new DataResponse(['status' => $this->payments->status($this->tuandikiane->moderator($draft), $reference)]);
		} catch (\Throwable) {
			return new DataResponse(['error' => 'unknown'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * A joiner's invite. Their slot is theirs once Tuandikiane hears MIMI took
	 * their payment; until then MIMI is the way in, and coming back from it they
	 * wait here until the slot is theirs, then go on to their card.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/p/t/join/{code}')]
	public function join(string $code, string $back = ''): TemplateResponse|RedirectResponse {
		$found = $this->tuandikiane->slotForJoinCode($code);
		if ($found === null) {
			return $this->page('expired', []);
		}
		if ($found['slot']['uid'] !== null) {
			return new RedirectResponse($this->tuandikiane->slotCard($found['slot']));
		}
		$here = $this->urls->linkToRouteAbsolute('libresign.tuandikiane.join', ['code' => $code]);
		if ($back === '') {
			return new RedirectResponse($this->signa->mimiEnrolmentUrl('', '', $here . '?back=1'));
		}
		return $this->page('waiting', ['refreshUrl' => $here . '?back=1']);
	}

	/** The number the moderator's prompt goes to: the one they wrote from. */
	private function moderatorPhone(TuandikianeDraft $draft): string {
		foreach ($draft->getSlots() ?? [] as $slot) {
			if ($slot['sender']) {
				return $slot['phone'];
			}
		}
		return '';
	}

	/** Null when MIMI accepts the answer as the moderator's passkey, else the response to give. */
	private function passkey(TuandikianeDraft $draft, array $response): ?DataResponse {
		try {
			$sub = $this->tuandikiane->moderatorSub($draft);
			if ($sub !== null && $this->signa->verifyPasskey($response, $sub)) {
				return null;
			}
		} catch (\Throwable $e) {
			$this->logger->warning('MIMI passkey check unavailable for Tuandikiane', ['exception' => $e]);
			return new DataResponse(['error' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
		return new DataResponse([
			'error' => 'rejected',
			'message' => $this->l10n->t('That passkey could not be confirmed. Use your MIMI passkey and try again.'),
		], Http::STATUS_FORBIDDEN);
	}

	/**
	 * WhatsApp's own browser can't run a passkey, so it gets the WhatsApp card's
	 * hand-off to Chrome. Null when the page can show.
	 */
	private function handOff(string $here): ?TemplateResponse {
		$userAgent = $this->request->getHeader('User-Agent');
		if (!WhatsAppSignController::isInAppBrowser($userAgent)) {
			return null;
		}
		Util::addStyle(Application::APP_ID, 'libresign-login');
		Util::addScript(Application::APP_ID, 'libresign-login');
		Util::addScript(Application::APP_ID, 'libresign-whatsapp-sign');
		$response = new TemplateResponse(Application::APP_ID, 'whatsapp_sign', [
			'state' => 'browser',
			'documentName' => '',
			'signUrl' => '',
			'target' => '',
			'chromeLink' => WhatsAppSignController::chromeLink($userAgent, $here),
		], TemplateResponse::RENDER_AS_GUEST);
		$response->cacheFor(0);
		return $response;
	}

	private function page(string $mode, array $params): TemplateResponse {
		Util::addStyle(Application::APP_ID, 'libresign-login');
		Util::addScript(Application::APP_ID, 'libresign-login');
		$policy = new ContentSecurityPolicy();
		if ($mode === 'review') {
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
			$params['chromeLink'] = WhatsAppSignController::chromeLink($this->request->getHeader('User-Agent'), $params['here']);
			// pdf.js renders the pages in a worker served from this instance.
			$policy->addAllowedWorkerSrcDomain("'self'");
		}
		$response = new TemplateResponse(Application::APP_ID, 'tuandikiane', ['mode' => $mode] + $params, TemplateResponse::RENDER_AS_GUEST);
		$response->setContentSecurityPolicy($policy);
		$response->cacheFor(0);
		return $response;
	}
}
