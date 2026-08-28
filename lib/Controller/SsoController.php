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
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class SsoController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'GET', url: '/sso')]
	public function handoff(int $providerId, int $force = 0, ?string $redirectUrl = null): RedirectResponse {
		if ($force === 1 && $this->userSession->isLoggedIn()) {
			$this->userSession->logout();
		}

		$params = [
			'providerId' => $providerId,
		];
		if ($redirectUrl !== null) {
			$params['redirectUrl'] = $redirectUrl;
		}

		return new RedirectResponse($this->urlGenerator->linkToRoute('user_oidc.login.login', $params));
	}
}
