<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Payment;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\Payment\FxEngineService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class FxEngineServiceTest extends TestCase {
	private LoggerInterface&MockObject $logger;
	private IClientService&MockObject $clientService;
	private IAppConfig&MockObject $appConfig;
	private FxEngineService $service;

	public function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->service = new FxEngineService(
			logger: $this->logger,
			clientService: $this->clientService,
			appConfig: $this->appConfig,
		);
	}

	public function testUsesEmergencyFallbackWhenNoApiKeysConfigured(): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(function (string $app, string $key, string $default = ''): string {
				self::assertSame(Application::APP_ID, $app);
				self::assertContains($key, ['fx_exchangerate_api_key', 'fx_openexchange_app_id']);
				return '';
			});

		$this->clientService->expects($this->never())->method('newClient');

		$result = $this->service->convert(10_000, 'TZS');

		self::assertSame('TZS', $result->displayCurrency);
		self::assertSame('emergency-fixed', $result->fxRateSource);
		self::assertSame('23.50', $result->fxRate);
	}
}
