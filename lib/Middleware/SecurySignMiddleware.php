<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Middleware;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Controller\PageController;
use OCA\Libresign\Controller\SignatureElementsController;
use OCA\Libresign\Controller\SignFileController;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\SecurySignService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class SecurySignMiddleware extends Middleware {
	public function __construct(
		private SecurySignService $signa,
		private IRequest $request,
		private IURLGenerator $urls,
		private LoggerInterface $logger,
		private IInitialState $initialState,
	) {
	}

	/**
	 * The page gate below only covers the Vue app. The signing API is reachable
	 * on its own, so the same requirement is enforced here before any signature
	 * is produced. Anonymous token signers never match: applies() needs a logged
	 * in user whose session came from the configured SecurySign provider.
	 */
	#[\Override]
	public function beforeController(Controller $controller, string $methodName): void {
		if (!$this->signa->applies()) {
			return;
		}

		// SecurySign owns the visible signature. Refusing the three endpoints that
		// change one is what makes that real: hiding a button in the Vue leaves the
		// API able to replace the card that goes on a document. Reads are
		// untouched, so the mirrored signature still renders everywhere.
		//
		// Only while a mirrored signature is actually there. With none, LibreSign's
		// own signature module is the fallback, or an import that failed would
		// leave the user unable to sign and unable to do anything about it.
		if ($controller instanceof SignatureElementsController
			&& in_array($methodName, ['createSignatureElement', 'patchSignatureElement', 'deleteSignatureElement'], true)
			&& $this->signa->hasMirroredSignature()) {
			throw new LibresignException('Your signature is managed in SecurySign and cannot be changed here.', Http::STATUS_FORBIDDEN);
		}

		if (!$controller instanceof SignFileController
			|| !in_array($methodName, ['signByFileId', 'signBySignerUuid'], true)) {
			return;
		}
		// The session's answer is enough here. When SecurySign signs, the handler
		// re-reads the certificate anyway, and forcing a fresh answer cost two
		// round trips to SecurySign on each of a signature's two requests. When
		// SecurySign is down, LibreSign's own engine signs instead, so an outage
		// must not stop the request.
		try {
			$ready = $this->signa->isReady();
		} catch (\Throwable $e) {
			$this->logger->warning('SecurySign readiness check failed before signing; the local engine signs if SecurySign cannot', ['exception' => $e]);
			return;
		}
		if (!$ready) {
			throw new LibresignException('Finish your SecurySign setup in GoPaperless before signing.', Http::STATUS_FORBIDDEN);
		}
	}

	#[\Override]
	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if (!$controller instanceof PageController || !$response instanceof TemplateResponse
			|| !$this->signa->applies()) {
			return $response;
		}
		// Every page, the public /p/sign one included: the sign button goes straight
		// to SecurySign, because the passkey is the confirmation. When SecurySign
		// turns out to be down, the server asks for the local confirm dialog.
		$this->initialState->provideInitialState('securysign_signs', $this->signa->signs());
		if ($response->getTemplateName() !== 'main') {
			return $response;
		}
		try {
			if ($this->signa->isReady()) {
				return $response;
			}
			return new RedirectResponse($this->urls->linkToRoute('libresign.securySign.onboard', [
				'returnTo' => SecurySignService::returnPath($this->request->getRequestUri()),
			]));
		} catch (\Throwable $e) {
			$this->logger->error('SecurySign readiness check failed on a page load', ['exception' => $e]);
			// A stale session is the user's to fix by signing in again, and reads
			// nothing like an outage. Saying "unavailable" for both sends them off to
			// wait for a service that is up.
			if ($e->getCode() === 401) {
				return $this->notice(
					'Reconnect to continue',
					'Your GoPaperless session is no longer linked to your SecurySign identity. Signing in again restores it. Nothing has been lost.',
					401,
					'Sign in again',
					$this->urls->linkToRoute('libresign.sso.handoff', ['providerId' => $this->signa->providerId(), 'force' => 1]),
				);
			}
			// An outage should go unnoticed: the page renders, and signing uses the
			// local engine with its usual confirm dialog until SecurySign is back.
			$this->initialState->provideInitialState('securysign_signs', false);
			return $response;
		}
	}

	/**
	 * The guest layout gives this the instance logo and theme, and both links give
	 * the user somewhere to go. The secondary one leaves LibreSign entirely, which
	 * is the only way out when the gate itself is what is broken — the rest of
	 * Nextcloud, sign-out included, is never gated.
	 */
	private function notice(string $title, string $message, int $status, string $actionLabel, string $actionUrl): TemplateResponse {
		$response = new TemplateResponse(Application::APP_ID, 'securysign_notice', [
			'title' => $title,
			'message' => $message,
			'actionLabel' => $actionLabel,
			'actionUrl' => $actionUrl,
			'secondaryLabel' => 'Back to GoPaperless',
			'secondaryUrl' => $this->urls->linkToDefaultPageUrl(),
		], TemplateResponse::RENDER_AS_GUEST);
		$response->setStatus($status);
		$response->cacheFor(0);
		return $response;
	}
}
