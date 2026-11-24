<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\OnboardingRecoveryController;
use OCA\Libresign\Db\IdentifyMethod;
use OCA\Libresign\Db\IdentifyMethodMapper;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Helper\OnboardingRecoveryCookie;
use OCA\Libresign\Service\Encryption\OnboardingContextEncryptionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class OnboardingRecoveryControllerTest extends TestCase {
	private const UUID = '0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10';
	private const SIGN_PAGE = '/apps/libresign/p/sign/0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10';
	private const INDEX_PAGE = '/apps/libresign/';
	private const REGISTER_URL = 'https://tendaworld.com/get-started';
	private const SIGNER_EMAIL = 'signer@example.com';

	/** @var array<string, mixed> */
	private array $config = [];
	private SignRequestMapper&MockObject $signRequestMapper;
	private IdentifyMethodMapper&MockObject $identifyMethodMapper;
	private OnboardingRecoveryCookie $cookie;
	private OnboardingRecoveryController $controller;

	protected function setUp(): void {
		$this->config = [
			'onboarding_recovery_enabled' => true,
			'register_url' => self::REGISTER_URL,
		];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(fn (string $a, string $k, bool $d = false): bool => (bool)($this->config[$k] ?? $d));
		$appConfig->method('getValueString')->willReturnCallback(fn (string $a, string $k, string $d = ''): string => (string)($this->config[$k] ?? $d));

		$systemConfig = $this->createMock(IConfig::class);
		$systemConfig->method('getSystemValueString')->willReturn('instance-secret');
		$encAppConfig = $this->createMock(IAppConfig::class);
		$encAppConfig->method('getValueString')->willReturn('');
		$this->cookie = new OnboardingRecoveryCookie(new OnboardingContextEncryptionService($systemConfig, $encAppConfig));

		$this->signRequestMapper = $this->createMock(SignRequestMapper::class);
		$this->identifyMethodMapper = $this->createMock(IdentifyMethodMapper::class);

		$request = $this->createMock(IRequest::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(fn (string $route, array $p = []): string => match ($route) {
			'libresign.page.sign' => self::SIGN_PAGE,
			'libresign.page.index' => self::INDEX_PAGE,
			default => self::fail('unexpected route ' . $route),
		});

		$this->controller = new OnboardingRecoveryController(
			$request,
			$urlGenerator,
			$appConfig,
			$this->signRequestMapper,
			$this->identifyMethodMapper,
			$this->cookie,
		);
	}

	private function withEmailMethod(string $email = self::SIGNER_EMAIL): void {
		$signRequest = $this->createMock(SignRequest::class);
		$signRequest->method('getId')->willReturn(42);
		$this->signRequestMapper->method('getByUuid')->with(self::UUID)->willReturn($signRequest);

		$method = $this->createMock(IdentifyMethod::class);
		$method->method('getIdentifierKey')->willReturn('email');
		$method->method('getIdentifierValue')->willReturn($email);
		$this->identifyMethodMapper->method('getIdentifyMethodsFromSignRequestId')->with(42)->willReturn([$method]);
	}

	private function cookieValue($response): ?string {
		foreach ($response->getCookies() as $name => $cookie) {
			if ($name === OnboardingRecoveryCookie::NAME) {
				return $cookie['value'] ?? null;
			}
		}
		return null;
	}

	public function testRedirectsToTendaworldAndIssuesCookie(): void {
		$this->withEmailMethod();

		$response = $this->controller->start(self::UUID);

		$this->assertSame(self::REGISTER_URL, $response->getRedirectUrl());
		$value = $this->cookieValue($response);
		$this->assertNotNull($value);
		$this->assertSame(
			['uuid' => self::UUID, 'email' => self::SIGNER_EMAIL],
			$this->cookie->verify($value),
		);
	}

	public function testDisabledFeatureFallsBackToSignPageWithoutCookie(): void {
		$this->config['onboarding_recovery_enabled'] = false;
		$this->withEmailMethod();

		$response = $this->controller->start(self::UUID);

		$this->assertSame(self::SIGN_PAGE, $response->getRedirectUrl());
		$this->assertNull($this->cookieValue($response));
	}

	public function testMalformedUuidFallsBackToIndex(): void {
		$response = $this->controller->start('not-a-uuid');
		$this->assertSame(self::INDEX_PAGE, $response->getRedirectUrl());
		$this->assertNull($this->cookieValue($response));
	}

	public function testNoEmailMethodFallsBackToSignPage(): void {
		$signRequest = $this->createMock(SignRequest::class);
		$signRequest->method('getId')->willReturn(42);
		$this->signRequestMapper->method('getByUuid')->willReturn($signRequest);
		$this->identifyMethodMapper->method('getIdentifyMethodsFromSignRequestId')->willReturn([]);

		$response = $this->controller->start(self::UUID);

		$this->assertSame(self::SIGN_PAGE, $response->getRedirectUrl());
		$this->assertNull($this->cookieValue($response));
	}

	public function testUnknownSignRequestFallsBackToSignPage(): void {
		$this->signRequestMapper->method('getByUuid')->willThrowException(new DoesNotExistException('nope'));

		$response = $this->controller->start(self::UUID);

		$this->assertSame(self::SIGN_PAGE, $response->getRedirectUrl());
		$this->assertNull($this->cookieValue($response));
	}
}
