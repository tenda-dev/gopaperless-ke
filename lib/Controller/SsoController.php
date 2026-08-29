<?php

declare(strict_types=1);

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class SsoController extends Controller {
	private const ALLOWED_REDIRECT_PATH_PREFIX = '/apps/libresign/';

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private IAppConfig $appConfig,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'GET', url: '/sso')]
	public function handoff(int $providerId, int $force = 0, ?string $redirectUrl = null): RedirectResponse {
		if (!$this->appConfig->getValueBool(Application::APP_ID, 'oidc_sso_handoff_enabled', false)) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('libresign.page.index'));
		}

		if ($force === 1 && $this->userSession->isLoggedIn()) {
			$this->userSession->logout();
		}

		$redirectUrl = $this->getSafeRedirectUrl($redirectUrl);

		$params = [
			'providerId' => $providerId,
			'redirectUrl' => $redirectUrl,
		];

		return new RedirectResponse($this->urlGenerator->linkToRoute('user_oidc.login.login', $params));
	}

	/**
	 * Ensure the client-supplied redirect target stays within this LibreSign
	 * instance. Only same-origin absolute URLs or app-relative paths under
	 * /apps/libresign/ are accepted; everything else falls back to the app root.
	 */
	private function getSafeRedirectUrl(?string $redirectUrl): string {
		$default = $this->urlGenerator->linkToRoute('libresign.page.index');

		if ($redirectUrl === null || $redirectUrl === '') {
			return $default;
		}

		// Relative app path
		if (str_starts_with($redirectUrl, self::ALLOWED_REDIRECT_PATH_PREFIX)) {
			return $redirectUrl;
		}

		// Same-origin absolute URL
		$baseUrl = $this->urlGenerator->getBaseUrl();
		if (str_starts_with($redirectUrl, $baseUrl . self::ALLOWED_REDIRECT_PATH_PREFIX)) {
			return $redirectUrl;
		}

		return $default;
	}
}
