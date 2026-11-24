<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Helper;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Helper\OnboardingRecoveryCookie;
use OCA\Libresign\Service\Encryption\OnboardingContextEncryptionService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class OnboardingRecoveryCookieTest extends TestCase {
	private const UUID = '0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10';
	private const EMAIL = 'signer@example.com';
	private const NOW = 1_800_000_000;

	private function encryption(string $secret = 'instance-secret'): OnboardingContextEncryptionService {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('secret', '')->willReturn($secret);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		return new OnboardingContextEncryptionService($config, $appConfig);
	}

	private function cookie(?OnboardingContextEncryptionService $enc = null): OnboardingRecoveryCookie {
		return new OnboardingRecoveryCookie($enc ?? $this->encryption());
	}

	public function testRoundTrip(): void {
		$cookie = $this->cookie();
		$value = $cookie->issue(self::UUID, self::EMAIL, self::NOW);
		$this->assertNotNull($value);
		$this->assertSame(
			['uuid' => self::UUID, 'email' => self::EMAIL],
			$cookie->verify($value, self::NOW + 1),
		);
	}

	public function testEmailIsNotReadableInRawCookie(): void {
		$value = (string)$this->cookie()->issue(self::UUID, self::EMAIL, self::NOW);
		$this->assertStringNotContainsString(self::EMAIL, $value);
		$this->assertStringNotContainsString('example.com', $value);
	}

	public function testIssueRejectsInvalidUuid(): void {
		$this->assertNull($this->cookie()->issue('not-a-uuid', self::EMAIL, self::NOW));
	}

	public function testIssueRejectsInvalidEmail(): void {
		$this->assertNull($this->cookie()->issue(self::UUID, 'not-an-email', self::NOW));
		$this->assertNull($this->cookie()->issue(self::UUID, '', self::NOW));
	}

	public function testVerifyRejectsExpired(): void {
		$value = (string)$this->cookie()->issue(self::UUID, self::EMAIL, self::NOW);
		$this->assertNull($this->cookie()->verify($value, self::NOW + OnboardingRecoveryCookie::TTL + 1));
	}

	public function testVerifyRejectsTamperedCiphertext(): void {
		$value = (string)$this->cookie()->issue(self::UUID, self::EMAIL, self::NOW);
		$payload = base64_decode(substr($value, 3), true);
		$this->assertNotFalse($payload);
		$last = strlen($payload) - 1;
		$payload[$last] = $payload[$last] ^ "\x01";
		$this->assertNull($this->cookie()->verify('v1:' . base64_encode($payload), self::NOW + 1));
	}

	public function testVerifyRejectsForeignKey(): void {
		$value = (string)$this->cookie($this->encryption('secret-A'))->issue(self::UUID, self::EMAIL, self::NOW);
		$this->assertNull($this->cookie($this->encryption('secret-B'))->verify($value, self::NOW + 1));
	}

	public function testVerifyRejectsWrongVersion(): void {
		// Seal a structurally valid but wrong-version payload with the same key.
		$enc = $this->encryption();
		$sealed = $enc->seal((string)json_encode(['v' => 2, 'uuid' => self::UUID, 'email' => self::EMAIL, 'exp' => self::NOW + 100]));
		$this->assertNull($this->cookie($enc)->verify($sealed, self::NOW + 1));
	}

	public function testVerifyRejectsMissingFields(): void {
		$enc = $this->encryption();
		$cookie = $this->cookie($enc);
		$this->assertNull($cookie->verify($enc->seal((string)json_encode(['v' => 1, 'email' => self::EMAIL, 'exp' => self::NOW + 100])), self::NOW + 1));
		$this->assertNull($cookie->verify($enc->seal((string)json_encode(['v' => 1, 'uuid' => self::UUID, 'exp' => self::NOW + 100])), self::NOW + 1));
	}

	public function testVerifyRejectsEmptyAndGarbage(): void {
		$cookie = $this->cookie();
		$this->assertNull($cookie->verify(null, self::NOW));
		$this->assertNull($cookie->verify('', self::NOW));
		$this->assertNull($cookie->verify('garbage-no-prefix', self::NOW));
		$this->assertNull($cookie->verify('v1:' . str_repeat('A', 2000), self::NOW));
	}
}
