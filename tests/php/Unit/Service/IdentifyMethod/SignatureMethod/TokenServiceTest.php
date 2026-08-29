<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\IdentifyMethod\SignatureMethod;

use OCA\Libresign\Service\IdentifyMethod\SignatureMethod\TokenService;
use OCA\Libresign\Service\MailService;
use OCA\Libresign\Service\SMS\SMSService;
use OCA\Libresign\Service\SMS\Webhook\WebhookService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\Accounts\IAccountManager;
use OCP\IL10N;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class TokenServiceTest extends TestCase {
	private ISecureRandom&MockObject $secureRandom;
	private IHasher&MockObject $hasher;
	private TokenService $service;

	public function setUp(): void {
		parent::setUp();

		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->hasher = $this->createMock(IHasher::class);

		$this->service = new class($this->secureRandom, $this->hasher, $this->createMock(MailService::class), $this->createMock(IL10N::class), $this->createMock(SMSService::class), $this->createMock(LoggerInterface::class), $this->createMock(IAccountManager::class), $this->createMock(WebhookService::class), ) extends TokenService {
			public ?object $capturedGateway = null;
			public ?string $capturedIdentifier = null;
			public ?string $capturedMessage = null;

			private function getGateway(string $gatewayName): object {
				return new class($this) {
					public function __construct(
						private TokenService $testCase,
					) {
					}

					public function isComplete(): bool {
						return true;
					}

					public function send(string $identifier, string $message): void {
						$this->testCase->capturedIdentifier = $identifier;
						$this->testCase->capturedMessage = $message;
					}
				};
			}
		};
	}

	public function testSendCodeByGatewayDispatchesCode(): void {
		$this->secureRandom->method('generate')
			->with(6, ISecureRandom::CHAR_DIGITS)
			->willReturn('123456');

		$this->hasher->method('hash')
			->with('123456')
			->willReturn('hashed-123456');

		$result = $this->service->sendCodeByGateway('+254700000000', 'sms');

		self::assertSame('hashed-123456', $result);
		self::assertSame('+254700000000', $this->service->capturedIdentifier);
		self::assertStringContainsString('123456', $this->service->capturedMessage ?? '');
	}
}
