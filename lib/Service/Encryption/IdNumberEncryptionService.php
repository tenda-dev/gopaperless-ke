<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Encryption;

use OCA\Libresign\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * Encrypts and decrypts government-issued identification numbers at rest.
 *
 * Ciphertext format:
 *   "v1:" . base64( IV (12 bytes) | GCM tag (16 bytes) | ciphertext )
 *
 * Key material resolution order:
 * 1. App-config key `id_number_encryption_key` (operator override,
 *    enables scheduled key rotation).
 * 2. The Nextcloud instance secret (`config.php` `secret`).
 *
 * The raw material is never used directly as the cipher key; it is
 * expanded with HKDF-SHA256 so any non-empty string is acceptable.
 *
 * Rotating or losing the key material makes existing ciphertext
 * undecryptable: decryption fails closed with a RuntimeException
 * instead of returning garbage.
 */
final class IdNumberEncryptionService {
	/**
	 * AppConfig key holding optional operator-supplied key material.
	 */
	public const APP_CONFIG_KEY = 'id_number_encryption_key';

	/**
	 * Ciphertext format/version prefix.
	 */
	private const PREFIX = 'v1:';

	private const CIPHER = 'aes-256-gcm';

	private const IV_BYTES = 12;

	private const TAG_BYTES = 16;

	private const HKDF_INFO = 'libresign-id-number-encryption';

	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * Encrypts a plaintext id number for persistence.
	 *
	 * Null and empty values pass through unchanged so nullable
	 * columns keep their semantics.
	 */
	public function encrypt(
		?string $plaintext,
	): ?string {

		if ($plaintext === null || $plaintext === '') {
			return $plaintext;
		}

		$iv = random_bytes(self::IV_BYTES);
		$tag = '';

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$this->key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
		);

		if ($ciphertext === false) {
			throw new \RuntimeException(
				'Failed to encrypt id_number.',
			);
		}

		return self::PREFIX
			. base64_encode($iv . $tag . $ciphertext);
	}

	/**
	 * Decrypts a persisted id number.
	 *
	 * Null and empty values pass through unchanged. Values without the
	 * ciphertext prefix are assumed to be pre-migration plaintext and
	 * are returned as-is so legacy rows stay readable.
	 *
	 * @throws \RuntimeException When the value is encrypted but cannot
	 *                           be authenticated/decrypted with the current key material.
	 */
	public function decrypt(
		?string $stored,
	): ?string {

		if ($stored === null || $stored === '') {
			return $stored;
		}

		if (!$this->isEncrypted($stored)) {
			return $stored;
		}

		$payload = base64_decode(
			substr($stored, strlen(self::PREFIX)),
			true,
		);

		if ($payload === false
			|| strlen($payload) < self::IV_BYTES + self::TAG_BYTES
		) {
			throw new \RuntimeException(
				'Malformed id_number ciphertext.',
			);
		}

		$iv = substr($payload, 0, self::IV_BYTES);
		$tag = substr(
			$payload,
			self::IV_BYTES,
			self::TAG_BYTES,
		);
		$ciphertext = substr(
			$payload,
			self::IV_BYTES + self::TAG_BYTES,
		);

		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$this->key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
		);

		if ($plaintext === false) {
			throw new \RuntimeException(
				'Failed to decrypt id_number: key mismatch or corrupted data.',
			);
		}

		return $plaintext;
	}

	/**
	 * Whether the stored value carries the ciphertext format prefix.
	 */
	public function isEncrypted(
		string $stored,
	): bool {
		return str_starts_with($stored, self::PREFIX);
	}

	/**
	 * Derives the 256-bit cipher key from the configured key material.
	 */
	private function key(): string {
		$material = $this->appConfig->getValueString(
			Application::APP_ID,
			self::APP_CONFIG_KEY,
			'',
		);

		if ($material === '') {
			$material = $this->config->getSystemValueString(
				'secret',
				'',
			);
		}

		if ($material === '') {
			throw new \RuntimeException(
				'No key material available for id_number encryption.',
			);
		}

		return hash_hkdf(
			'sha256',
			$material,
			32,
			self::HKDF_INFO,
		);
	}
}
