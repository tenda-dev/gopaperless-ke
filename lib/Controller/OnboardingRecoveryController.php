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
use OCA\Libresign\Helper\OnboardingRecoveryCookie;
use OCA\Libresign\Service\IdentifyMethodService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Preserves a signer's identity context while they complete external onboarding.
 *
 * Recovery is bound to the email identity associated with the signing request,
 * preventing callers from substituting an arbitrary address. The signer-specific
 * UUID keeps the context unambiguous for documents with multiple signers.
 */
class OnboardingRecoveryController extends Controller {
	private const UUID_PATTERN = '/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD';

	public function __construct(
		IRequest $request,
		private IURLGenerator $urlGenerator,
		private IAppConfig $appConfig,
		private SignRequestMapper $signRequestMapper,
		private IdentifyMethodMapper $identifyMethodMapper,
		private OnboardingRecoveryCookie $cookie,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/onboarding/start')]
	public function start(string $uuid = ''): RedirectResponse {
		$isUuid = preg_match(self::UUID_PATTERN, $uuid) === 1;
		$signPage = $isUuid
			? $this->urlGenerator->linkToRoute('libresign.page.sign', ['uuid' => $uuid])
			: $this->urlGenerator->linkToRoute('libresign.page.index');

		// Keep recovery opt-in and leave the existing signing flow unchanged when
		// disabled or given an invalid signer context.
		if (!$isUuid || !$this->appConfig->getValueBool(Application::APP_ID, 'onboarding_recovery_enabled', false)) {
			return new RedirectResponse($signPage);
		}

		$email = $this->resolveIntendedSignerEmail($uuid);
		$registerUrl = $this->appConfig->getValueString(Application::APP_ID, 'register_url', 'https://tendaworld.com/get-started');
		if ($email === null || $registerUrl === '') {
			// Recovery requires both a trusted signer identity and a valid destination.
			// Without either, preserve the existing signing experience.
			return new RedirectResponse($signPage);
		}

		$response = new RedirectResponse($registerUrl);
		$cookie = $this->cookie->issue($uuid, $email);
		if ($cookie !== null) {
			// Lax permits the context cookie on the signer's return navigation while
			// restricting it on cross-site subrequests. Issuance and invalidation must
			// use the same cookie scope to ensure the context can be cleared.
			$response->addCookie(
				OnboardingRecoveryCookie::NAME,
				$cookie,
				(new \DateTime())->setTimestamp(time() + OnboardingRecoveryCookie::TTL),
				'Lax',
			);
		}
		// Onboarding remains available even when recovery encryption cannot be
		// initialized; automatic context restoration is optional.
		return $response;
	}

	private function resolveIntendedSignerEmail(string $uuid): ?string {
		try {
			$signRequest = $this->signRequestMapper->getByUuid($uuid);
		} catch (DoesNotExistException) {
			return null;
		}

		foreach ($this->identifyMethodMapper->getIdentifyMethodsFromSignRequestId($signRequest->getId()) as $method) {
			if ($method->getIdentifierKey() !== IdentifyMethodService::IDENTIFY_EMAIL) {
				continue;
			}
			$email = trim((string)$method->getIdentifierValue());

			return $email === '' ? null : $email;
		}

		return null;
	}
}
