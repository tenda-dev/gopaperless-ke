<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Controller\AdminController;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Handler\CertificateEngine\CertificateEngineFactory;
use OCA\Libresign\Service\Certificate\ValidateService;
use OCA\Libresign\Service\CertificatePolicyService;
use OCA\Libresign\Service\DocMdp\ConfigService as DocMdpConfigService;
use OCA\Libresign\Service\FooterService;
use OCA\Libresign\Service\IdentifyMethodService;
use OCA\Libresign\Service\Install\ConfigureCheckService;
use OCA\Libresign\Service\Install\InstallService;
use OCA\Libresign\Service\Operations\Analytics\Report\PeriodReportService;
use OCA\Libresign\Service\ReminderService;
use OCA\Libresign\Service\SignatureBackgroundService;
use OCA\Libresign\Service\SignatureTextService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IEventSource;
use OCP\IEventSourceFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Focused coverage for the onboarding destination URL configuration added to
 * {@see AdminController::setOnboardingRecoveryConfig()}: authoritative backend
 * validation (absolute http(s) only), atomic reject on invalid input, and the
 * toggle-only (null URL) path that leaves the destination untouched.
 */
final class AdminControllerOnboardingRecoveryConfigTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private AdminController $controller;

	public function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$eventSourceFactory = $this->createMock(IEventSourceFactory::class);
		$eventSourceFactory->method('create')->willReturn($this->createMock(IEventSource::class));

		$this->controller = new AdminController(
			$this->createMock(IRequest::class),
			$this->appConfig,
			$this->createMock(ConfigureCheckService::class),
			$this->createMock(InstallService::class),
			$this->createMock(CertificateEngineFactory::class),
			$eventSourceFactory,
			$this->createMock(SignatureTextService::class),
			$l10n,
			$this->createMock(ISession::class),
			$this->createMock(SignatureBackgroundService::class),
			$this->createMock(CertificatePolicyService::class),
			$this->createMock(ValidateService::class),
			$this->createMock(ReminderService::class),
			$this->createMock(FooterService::class),
			$this->createMock(DocMdpConfigService::class),
			$this->createMock(IdentifyMethodService::class),
			$this->createMock(FileMapper::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(PeriodReportService::class),
		);
	}

	public function testSavesValidUrlAndEnabledFlag(): void {
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with(Application::APP_ID, Application::CONFIG_REGISTER_URL, 'https://staging.tendaworld.com/get-started');
		$this->appConfig->expects($this->once())
			->method('setValueBool')
			->with(Application::APP_ID, 'onboarding_recovery_enabled', true);

		$response = $this->controller->setOnboardingRecoveryConfig(true, 'https://staging.tendaworld.com/get-started');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testTrimsTheUrlBeforePersisting(): void {
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with(Application::APP_ID, Application::CONFIG_REGISTER_URL, 'https://tendaworld.com/get-started');

		$response = $this->controller->setOnboardingRecoveryConfig(false, '  https://tendaworld.com/get-started  ');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testNullUrlLeavesDestinationUntouchedAndSetsEnabled(): void {
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->appConfig->expects($this->once())
			->method('setValueBool')
			->with(Application::APP_ID, 'onboarding_recovery_enabled', true);

		$response = $this->controller->setOnboardingRecoveryConfig(true, null);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/**
	 * @dataProvider invalidUrlProvider
	 */
	public function testRejectsInvalidUrlAtomicallyWithoutAnyWrite(string $url): void {
		// Atomic reject: neither the URL nor the enabled flag is written on invalid input.
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->appConfig->expects($this->never())->method('setValueBool');

		$response = $this->controller->setOnboardingRecoveryConfig(true, $url);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('error', $data['status'] ?? null);
	}

	public static function invalidUrlProvider(): array {
		return [
			'empty' => [''],
			'whitespace only' => ['   '],
			'relative path' => ['/get-started'],
			'no scheme host' => ['tendaworld.com/get-started'],
			'javascript scheme' => ['javascript:alert(1)'],
			'data scheme' => ['data:text/html,<x>'],
			'ftp scheme' => ['ftp://tendaworld.com/get-started'],
		];
	}
}
