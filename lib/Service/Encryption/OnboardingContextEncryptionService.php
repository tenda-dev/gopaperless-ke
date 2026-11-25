<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Encryption;

use OCA\Libresign\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * Encrypts and authenticates onboarding-recovery context using AES-256-GCM.
 *
 * The implementation reuses the in-tree encryption pattern while deriving a
 * separate key through HKDF domain separation. This prevents the recovery cookie
 * from sharing the id-number encryption key domain.
 *
 * Decryption fails closed: values without the expected ciphertext format or
 * valid authentication are rejected rather than treated as plaintext. The cookie
 * carries navigation context only; signing authorization remains independent.
 *
 * Format: "v1:" . base64(IV | tag | ciphertext)
 *
 * Key material comes from the optional onboarding-recovery key or the
 * Nextcloud instance secret. Losing or rotating it invalidates outstanding
 * recovery cookies without affecting signing correctness.
 */
final class OnboardingContextEncryptionService {
	/** AppConfig key holding optional operator-supplied key material. */
	public const APP_CONFIG_KEY = 'onboarding_recovery_encryption_key';

	private const PREFIX = 'v1:';
	private const CIPHER = 'aes-256-gcm';
	private const IV_BYTES = 12;
	private const TAG_BYTES = 16;
	// Keeps this cookie's derived key isolated from other encryption purposes.
	private const HKDF_INFO = 'libresign-onboarding-recovery-cookie';

	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @throws \RuntimeException When no key material is available or the cipher fails.
	 */
	public function seal(string $plaintext): string {
		$iv = random_bytes(self::IV_BYTES);
		$tag = '';
		$ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
		if ($ciphertext === false) {
			throw new \RuntimeException('Failed to seal onboarding recovery context.');
		}

		return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
	}

	/**
	 * Decrypts an authenticated payload, returning null when it cannot be trusted.
	 *
	 * @return ?string Plaintext only after successful authentication.
	 */
	public function open(?string $sealed): ?string {
		if ($sealed === null || $sealed === '' || !str_starts_with($sealed, self::PREFIX)) {
			return null;
		}

		$payload = base64_decode(substr($sealed, strlen(self::PREFIX)), true);
		if ($payload === false || strlen($payload) < self::IV_BYTES + self::TAG_BYTES) {
			return null;
		}

		$iv = substr($payload, 0, self::IV_BYTES);
		$tag = substr($payload, self::IV_BYTES, self::TAG_BYTES);
		$ciphertext = substr($payload, self::IV_BYTES + self::TAG_BYTES);

		try {
			$plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
		} catch (\Throwable) {
			return null;
		}

		return $plaintext === false ? null : $plaintext;
	}

	private function key(): string {
		$material = $this->appConfig->getValueString(Application::APP_ID, self::APP_CONFIG_KEY, '');
		if ($material === '') {
			$material = $this->config->getSystemValueString('secret', '');
		}
		if ($material === '') {
			throw new \RuntimeException('No key material available for onboarding recovery encryption.');
		}

		return hash_hkdf('sha256', $material, 32, self::HKDF_INFO);
	}
}
