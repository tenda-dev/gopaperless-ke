<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Encryption;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\Encryption\IdNumberEncryptionService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class IdNumberEncryptionServiceTest extends TestCase {
	private function service(
		string $systemSecret = 'instance-secret',
		string $appConfigKey = '',
	): IdNumberEncryptionService {

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')
			->with('secret', '')
			->willReturn($systemSecret);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->with(
				Application::APP_ID,
				IdNumberEncryptionService::APP_CONFIG_KEY,
				'',
			)
			->willReturn($appConfigKey);

		return new IdNumberEncryptionService(
			$config,
			$appConfig,
		);
	}

	public function testEncryptDecryptRoundTrip(): void {
		$service = $this->service();

		$plaintext = '12345678';

		$encrypted = $service->encrypt($plaintext);

		$this->assertNotNull($encrypted);
		$this->assertTrue($service->isEncrypted($encrypted));
		$this->assertStringNotContainsString($plaintext, $encrypted);
		$this->assertSame(
			$plaintext,
			$service->decrypt($encrypted),
		);
	}

	public function testEncryptionIsNonDeterministic(): void {
		$service = $this->service();

		$first = $service->encrypt('12345678');
		$second = $service->encrypt('12345678');

		$this->assertNotSame($first, $second);
	}

	public function testAppConfigKeyTakesPrecedenceOverSystemSecret(): void {
		$byAppKey = $this->service(
			systemSecret: 'instance-secret',
			appConfigKey: 'operator-key',
		);

		$encrypted = $byAppKey->encrypt('12345678');

		$this->assertSame(
			'12345678',
			$byAppKey->decrypt($encrypted),
		);

		$bySystemSecret = $this->service(
			systemSecret: 'instance-secret',
		);

		$this->expectException(\RuntimeException::class);
		$bySystemSecret->decrypt($encrypted);
	}

	public function testNullAndEmptyPassThrough(): void {
		$service = $this->service();

		$this->assertNull($service->encrypt(null));
		$this->assertSame('', $service->encrypt(''));
		$this->assertNull($service->decrypt(null));
		$this->assertSame('', $service->decrypt(''));
	}

	public function testLegacyPlaintextDecryptsAsIs(): void {
		$service = $this->service();

		$this->assertSame(
			'12345678',
			$service->decrypt('12345678'),
		);
	}

	public function testDecryptFailsAfterKeyRotation(): void {
		$oldKeyService = $this->service(
			systemSecret: 'old-secret',
		);
		$encrypted = $oldKeyService->encrypt('12345678');

		$newKeyService = $this->service(
			systemSecret: 'new-secret',
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/decrypt/');
		$newKeyService->decrypt($encrypted);
	}

	public function testDecryptFailsOnTamperedCiphertext(): void {
		$service = $this->service();

		$encrypted = (string)$service->encrypt('12345678');
		$payload = base64_decode(substr($encrypted, 3), true);
		$this->assertNotFalse($payload);

		// Flip one bit inside the ciphertext body.
		$last = strlen($payload) - 1;
		$payload[$last] = $payload[$last] ^ "\x01";
		$tampered = 'v1:' . base64_encode($payload);

		$this->expectException(\RuntimeException::class);
		$service->decrypt($tampered);
	}

	public function testDecryptFailsOnMalformedCiphertext(): void {
		$service = $this->service();

		$this->expectException(\RuntimeException::class);
		$service->decrypt('v1:not-valid-base64!!!');
	}
}
