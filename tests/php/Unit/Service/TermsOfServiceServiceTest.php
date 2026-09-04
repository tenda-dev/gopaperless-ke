<?php

declare(strict_types=1);

namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Service\TermsOfServiceService;
use OCP\App\IAppManager;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class TermsOfServiceServiceTest extends TestCase {
	private IAppManager $appManager;
	private IServerContainer $serverContainer;
	private IUserSession $userSession;

	protected function setUp(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->serverContainer = $this->createMock(IServerContainer::class);
		$this->userSession = $this->createMock(IUserSession::class);
	}

	public function testUnsignedUserHasPendingTerms(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->appManager->method('isEnabledForUser')->with('terms_of_service', $user)->willReturn(true);
		$this->serverContainer->method('get')
			->with('OCA\\TermsOfService\\Checker')
			->willReturn(new class {
				public function currentUserHasSigned(): bool {
					return false;
				}
			});

		self::assertTrue($this->getService()->hasPendingTerms());
	}

	public function testUnavailableCheckerKeepsTheUserOutOfLibreSign(): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->appManager->method('isEnabledForUser')->with('terms_of_service', $user)->willReturn(true);
		$this->serverContainer->method('get')->willThrowException(new \RuntimeException());

		self::assertTrue($this->getService()->hasPendingTerms());
	}

	private function getService(): TermsOfServiceService {
		return new TermsOfServiceService(
			$this->appManager,
			$this->serverContainer,
			$this->userSession,
		);
	}
}
