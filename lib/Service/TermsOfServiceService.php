<?php

declare(strict_types=1);

namespace OCA\Libresign\Service;

use OCP\App\IAppManager;
use OCP\IServerContainer;
use OCP\IUserSession;

class TermsOfServiceService {
	private const APP_ID = 'terms_of_service';
	private const CHECKER_SERVICE = 'OCA\\TermsOfService\\Checker';

	public function __construct(
		private IAppManager $appManager,
		private IServerContainer $serverContainer,
		private IUserSession $userSession,
	) {
	}

	public function hasPendingTerms(): bool {
		$user = $this->userSession->getUser();
		if ($user === null || !$this->appManager->isEnabledForUser(self::APP_ID, $user)) {
			return false;
		}

		try {
			$checker = $this->serverContainer->get(self::CHECKER_SERVICE);
			return !is_object($checker)
				|| !method_exists($checker, 'currentUserHasSigned')
				|| !$checker->currentUserHasSigned();
		} catch (\Throwable) {
			return true;
		}
	}
}
