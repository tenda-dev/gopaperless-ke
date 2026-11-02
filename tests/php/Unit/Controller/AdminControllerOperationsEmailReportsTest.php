<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use InvalidArgumentException;
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
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IAppConfig;
use OCP\IEventSource;
use OCP\IEventSourceFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class AdminControllerOperationsEmailReportsTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private PeriodReportService&MockObject $periodReportService;
	private LoggerInterface&MockObject $logger;
	private IUserSession&MockObject $userSession;
	private AdminController $controller;

	public function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->periodReportService = $this->createMock(PeriodReportService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
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
			$this->userSession,
			$this->logger,
			$this->periodReportService,
		);
	}

	public function testSavesThroughTheServiceAndReturnsTheNextRun(): void {
		$schedule = ['frequency' => 'daily', 'time' => '08:00'];
		$next = ['dueAt' => '2026-11-02T05:00:00Z', 'periodFrom' => '2026-11-01', 'periodTo' => '2026-11-01', 'timezone' => 'Africa/Nairobi'];
		$this->periodReportService->expects($this->once())->method('saveConfig')->with(true, $schedule);
		$this->periodReportService->method('nextRun')->willReturn($next);

		$response = $this->controller->setOperationsEmailReportsConfig(true, $schedule);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['message' => 'Settings saved', 'next' => $next], $response->getData());
	}

	public function testInvalidScheduleIsABadRequestAndNothingIsReturnedAsSaved(): void {
		$this->periodReportService->method('saveConfig')->willThrowException(new InvalidArgumentException('Invalid time'));
		$this->periodReportService->expects($this->never())->method('nextRun');

		$response = $this->controller->setOperationsEmailReportsConfig(true, ['time' => '25:00']);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	public function testUnexpectedFailureIsA500WithoutLeakingTheMessage(): void {
		$this->periodReportService->method('saveConfig')->willThrowException(new \RuntimeException('secret db detail'));

		$response = $this->controller->setOperationsEmailReportsConfig(true, []);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertStringNotContainsString('secret db detail', json_encode($response->getData()));
	}

	public function testTheEmailSetterNeverTouchesTheDashboardKeysItself(): void {
		$this->appConfig->expects($this->never())->method('setValueBool');
		$this->appConfig->expects($this->never())->method('setValueArray');
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->controller->setOperationsEmailReportsConfig(true, ['frequency' => 'daily']);
	}

	public function testTheDashboardSetterDoesNotTouchTheEmailKeys(): void {
		$this->periodReportService->expects($this->never())->method('saveConfig');
		$written = [];
		$this->appConfig->method('setValueBool')->willReturnCallback(function (string $app, string $key) use (&$written): bool {
			$written[] = $key;
			return true;
		});
		$this->appConfig->method('setValueArray')->willReturnCallback(function (string $app, string $key) use (&$written): bool {
			$written[] = $key;
			return true;
		});

		$this->controller->setOperationsDashboardConfig(true, ['ops']);

		$this->assertSame(['operations_dashboard_enabled', 'operations_groups'], $written);
	}

	public static function setters(): array {
		return [
			'dashboard' => ['setOperationsDashboardConfig'],
			'email reports' => ['setOperationsEmailReportsConfig'],
		];
	}

	#[DataProvider('setters')]
	public function testSettersAreAdminOnlyByNotDeclaringNoAdminRequired(string $method): void {
		$reflection = new \ReflectionMethod(AdminController::class, $method);

		$this->assertSame([], $reflection->getAttributes(NoAdminRequired::class));
		$this->assertCount(1, $reflection->getAttributes(ApiRoute::class));
	}

	public function testEmailSetterRouteUrl(): void {
		$route = (new \ReflectionMethod(AdminController::class, 'setOperationsEmailReportsConfig'))
			->getAttributes(ApiRoute::class)[0]->newInstance();

		$this->assertSame('POST', $route->getVerb());
		$this->assertSame('/api/{apiVersion}/admin/operations-email-reports/config', $route->getUrl());
	}

	private function signedInAdmin(string $email): void {
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn($email);
		$user->method('getDisplayName')->willReturn('Ada Admin');
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testTestEmailGoesOnlyToTheSignedInAdminsOwnAddress(): void {
		$this->signedInAdmin('ada@example.org');
		$this->periodReportService->expects($this->once())->method('sendTest')->with('ada@example.org', 'Ada Admin');

		$response = $this->controller->sendOperationsEmailReportTest();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertStringNotContainsString('ada@example.org', json_encode($response->getData()));
	}

	public function testTestEmailGoesToTheTypedAddressWhenGiven(): void {
		$this->signedInAdmin('ada@example.org');
		$this->periodReportService->expects($this->once())->method('sendTest')->with('qa@example.org', 'Ada Admin');

		$response = $this->controller->sendOperationsEmailReportTest('  qa@example.org ');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertStringNotContainsString('qa@example.org', json_encode($response->getData()));
	}

	public function testTypedAddressWorksEvenWhenTheAdminHasNoAccountEmail(): void {
		$this->signedInAdmin('');
		$this->periodReportService->expects($this->once())->method('sendTest')->with('qa@example.org', 'Ada Admin');

		$this->assertSame(Http::STATUS_OK, $this->controller->sendOperationsEmailReportTest('qa@example.org')->getStatus());
	}

	public function testTestEmailWithoutAnAccountAddressIsABadRequest(): void {
		$this->signedInAdmin('');
		$this->periodReportService->expects($this->never())->method('sendTest');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->sendOperationsEmailReportTest()->getStatus());
	}

	public function testTestEmailWithoutASessionIsABadRequest(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->periodReportService->expects($this->never())->method('sendTest');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->sendOperationsEmailReportTest()->getStatus());
	}

	public function testTestEmailInvalidAddressIsABadRequest(): void {
		$this->signedInAdmin('bad');
		$this->periodReportService->method('sendTest')->willThrowException(new InvalidArgumentException('x'));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->sendOperationsEmailReportTest()->getStatus());
	}

	public function testTestEmailTransportFailureIsA500WithoutLeakingDetail(): void {
		$this->signedInAdmin('ada@example.org');
		$this->periodReportService->method('sendTest')->willThrowException(new \RuntimeException('smtp secret ada@example.org'));
		$this->logger->expects($this->once())->method('error')->with($this->anything(), ['exception' => \RuntimeException::class]);

		$response = $this->controller->sendOperationsEmailReportTest();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertStringNotContainsString('smtp secret', json_encode($response->getData()));
	}

	public function testTestEmailIsAdminOnlyAndRouted(): void {
		$reflection = new \ReflectionMethod(AdminController::class, 'sendOperationsEmailReportTest');
		$route = $reflection->getAttributes(ApiRoute::class)[0]->newInstance();

		$this->assertSame([], $reflection->getAttributes(NoAdminRequired::class));
		$this->assertSame('POST', $route->getVerb());
		$this->assertSame('/api/{apiVersion}/admin/operations-email-reports/test', $route->getUrl());
	}
}
