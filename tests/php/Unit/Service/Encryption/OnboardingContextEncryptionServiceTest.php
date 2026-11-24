<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Encryption;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\Encryption\OnboardingContextEncryptionService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class OnboardingContextEncryptionServiceTest extends TestCase {
	private function service(string $systemSecret = 'instance-secret', string $appConfigKey = ''): OnboardingContextEncryptionService {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('secret', '')->willReturn($systemSecret);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->with(Application::APP_ID, OnboardingContextEncryptionService::APP_CONFIG_KEY, '')
			->willReturn($appConfigKey);

		return new OnboardingContextEncryptionService($config, $appConfig);
	}

	public function testSealOpenRoundTrip(): void {
		$service = $this->service();
		$plaintext = '{"v":1,"uuid":"abc","email":"a@b.c","exp":123}';

		$sealed = $service->seal($plaintext);

		$this->assertStringStartsWith('v1:', $sealed);
		$this->assertStringNotContainsString('a@b.c', $sealed);
		$this->assertSame($plaintext, $service->open($sealed));
	}

	public function testSealIsNonDeterministic(): void {
		$service = $this->service();
		$this->assertNotSame($service->seal('payload'), $service->seal('payload'));
	}

	public function testAppConfigKeyTakesPrecedenceOverSystemSecret(): void {
		$byAppKey = $this->service(systemSecret: 'instance-secret', appConfigKey: 'operator-key');
		$sealed = $byAppKey->seal('payload');

		$this->assertSame('payload', $byAppKey->open($sealed));
		// A service without the operator key cannot open it.
		$this->assertNull($this->service(systemSecret: 'instance-secret')->open($sealed));
	}

	public function testOpenReturnsNullForNonCiphertext(): void {
		$service = $this->service();
		$this->assertNull($service->open(null));
		$this->assertNull($service->open(''));
		// No "legacy plaintext" passthrough: an unprefixed value is never trusted.
		$this->assertNull($service->open('plain-text-no-prefix'));
	}

	public function testOpenReturnsNullOnTamper(): void {
		$service = $this->service();
		$sealed = $service->seal('payload');
		$payload = base64_decode(substr($sealed, 3), true);
		$this->assertNotFalse($payload);
		$last = strlen($payload) - 1;
		$payload[$last] = $payload[$last] ^ "\x01";

		$this->assertNull($service->open('v1:' . base64_encode($payload)));
	}

	public function testOpenReturnsNullOnMalformedBase64(): void {
		$this->assertNull($this->service()->open('v1:not-valid-base64!!!'));
	}

	public function testOpenReturnsNullAfterKeyRotation(): void {
		$sealed = $this->service(systemSecret: 'old-secret')->seal('payload');
		$this->assertNull($this->service(systemSecret: 'new-secret')->open($sealed));
	}
}
