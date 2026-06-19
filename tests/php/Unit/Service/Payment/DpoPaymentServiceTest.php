<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Payment;

use OCA\Libresign\Service\Payment\DpoPaymentService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class DpoPaymentServiceTest extends TestCase {
	private IClientService&MockObject $clientService;
	private LoggerInterface&MockObject $logger;
	private IAppConfig&MockObject $appConfig;
	private DpoPaymentService $service;

	public function setUp(): void {
		parent::setUp();

		$this->clientService = $this->createMock(IClientService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->appConfig->method('getValueString')
			->willReturnCallback(function (string $app, string $key, string $default = ''): string {
				$values = [
					'dpo_endpoint' => 'https://dummy.dpo.api/',
					'dpo_company_token' => 'secret-token',
					'dpo_service_id' => '12345',
					'dpo_payment_url' => 'https://dummy.dpo.pay/',
					'gopaperless_callback_base_url' => 'https://callback.example/',
				];

				return $values[$key] ?? $default;
			});

		$this->service = new DpoPaymentService(
			clientService: $this->clientService,
			logger: $this->logger,
			appConfig: $this->appConfig,
		);
	}

	private function givenSuccessfulDpoResponse(string $body): IClient&MockObject {
		$client = $this->createMock(IClient::class);
		$this->clientService->method('newClient')->willReturn($client);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($body);

		$client->method('post')->willReturn($response);

		return $client;
	}

	public function testCreateTokenEscapesUserControlledValues(): void {
		$capturedXml = null;

		$client = $this->givenSuccessfulDpoResponse(
			'<API3G><Result>000</Result><ResultExplanation>OK</ResultExplanation><TransToken>TOKEN-123</TransToken></API3G>'
		);
		$client->expects($this->once())
			->method('post')
			->with(
				$this->equalTo('https://dummy.dpo.api/'),
				$this->callback(function (array $options) use (&$capturedXml): bool {
					$capturedXml = $options['body'];
					return true;
				})
			);

		$this->service->createToken(
			userEmail: 'user+test&foo@example.com',
			signUuid: 'ref<script>alert(1)</script>',
			amount: 99.99,
			redirectUrl: 'https://app.example.com/pay',
			currency: 'KES',
		);

		self::assertNotNull($capturedXml);
		self::assertStringContainsString('user+test&amp;foo@example.com', $capturedXml);
		self::assertStringContainsString('ref&lt;script&gt;alert(1)&lt;/script&gt;', $capturedXml);
		self::assertStringContainsString('<PaymentAmount>99.99</PaymentAmount>', $capturedXml);
		self::assertStringContainsString('<PaymentCurrency>KES</PaymentCurrency>', $capturedXml);
	}

	public function testDebugLogRedactsCompanyToken(): void {
		$capturedXml = null;

		$client = $this->createMock(IClient::class);
		$this->clientService->method('newClient')->willReturn($client);
		$client->method('post')->willReturnCallback(function (string $url, array $options) use (&$capturedXml): IResponse {
			$capturedXml = $options['body'];
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn(200);
			$response->method('getBody')->willReturn(
				'<API3G><Result>000</Result><ResultExplanation>OK</ResultExplanation><TransToken>TOKEN-123</TransToken></API3G>'
			);
			return $response;
		});

		$this->logger->expects($this->atLeastOnce())
			->method('debug')
			->with(
				$this->equalTo('DPO request XML'),
				$this->callback(function (array $context): bool {
					$loggedXml = $context['xml'] ?? '';
					return str_contains($loggedXml, '<CompanyToken>***REDACTED***</CompanyToken>')
						&& !str_contains($loggedXml, 'secret-token');
				})
			);

		$this->service->createToken(
			userEmail: 'user@example.com',
			signUuid: 'ref-123',
			amount: 10.0,
			redirectUrl: 'https://app.example.com/pay',
			currency: 'KES',
		);
	}

	public function testChargeTokenMobileEscapesInputValues(): void {
		$capturedXml = null;

		$client = $this->givenSuccessfulDpoResponse(
			'<API3G><StatusCode>130</StatusCode><ResultExplanation>Accepted</ResultExplanation></API3G>'
		);
		$client->expects($this->once())
			->method('post')
			->with(
				$this->equalTo('https://dummy.dpo.api/'),
				$this->callback(function (array $options) use (&$capturedXml): bool {
					$capturedXml = $options['body'];
					return true;
				})
			);

		$this->service->chargeTokenMobile(
			transactionToken: 'tok<123>',
			phone: '+2547&12345678',
			mno: 'mpesa&co',
			mnoCountry: 'kenya"Inject',
		);

		self::assertNotNull($capturedXml);
		self::assertStringContainsString('<TransactionToken>tok&lt;123&gt;</TransactionToken>', $capturedXml);
		self::assertStringContainsString('<PhoneNumber>2547&amp;12345678</PhoneNumber>', $capturedXml);
		self::assertStringContainsString('<MNO>mpesa&amp;co</MNO>', $capturedXml);
		self::assertStringContainsString('<MNOcountry>kenya&quot;Inject</MNOcountry>', $capturedXml);
	}
}
