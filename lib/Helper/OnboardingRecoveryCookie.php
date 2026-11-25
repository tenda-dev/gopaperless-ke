<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Helper;

use OCA\Libresign\Service\Encryption\OnboardingContextEncryptionService;

/**
 * Preserves an unregistered signer's signing context across external onboarding.
 *
 * The context is authenticated and encrypted to protect the intended signer's
 * identity and prevent tampering. It is used only to restore navigation;
 * authorization to sign remains governed by the existing signing flow.
 *
 * The invited email is preserved in its original form and normalized only when
 * comparing it with the authenticated identity.
 */
class OnboardingRecoveryCookie {
	public const NAME = 'gp_onboard';
	// Allows time for onboarding and authentication retries without leaving the
	// navigation context valid indefinitely.
	public const TTL = 1800;

	private const VERSION = 1;
	// Bound cookie input before decryption to avoid processing oversized values.
	private const MAX_LENGTH = 1024;
	private const UUID_PATTERN = '/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD';

	public function __construct(
		private OnboardingContextEncryptionService $encryption,
	) {
	}

	/**
	 * @param ?int $now Seconds since epoch; only for deterministic tests.
	 * @return ?string The sealed cookie value, or null when it cannot be issued.
	 */
	public function issue(string $uuid, string $email, ?int $now = null): ?string {
		if (preg_match(self::UUID_PATTERN, $uuid) !== 1) {
			return null;
		}
		$email = trim($email);
		if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			return null;
		}

		$payload = json_encode([
			'v' => self::VERSION,
			'uuid' => $uuid,
			'email' => $email,
			'exp' => ($now ?? time()) + self::TTL,
		]);
		if ($payload === false) {
			return null;
		}

		try {
			return $this->encryption->seal($payload);
		} catch (\Throwable) {
			// Recovery is optional; encryption failure must not prevent onboarding.
			return null;
		}
	}

	/**
	 * @param ?int $now Seconds since epoch, provided to make expiry tests deterministic.
	 * @return ?array{uuid: string, email: string} The recovered context, or null if
	 *         the cookie is invalid, tampered with, or expired.
	 */
	public function verify(?string $raw, ?int $now = null): ?array {
		if ($raw === null || $raw === '' || strlen($raw) > self::MAX_LENGTH) {
			return null;
		}

		$plain = $this->encryption->open($raw);
		if ($plain === null) {
			return null;
		}

		$data = json_decode($plain, true);
		if (!is_array($data) || ($data['v'] ?? null) !== self::VERSION) {
			return null;
		}

		$uuid = (string)($data['uuid'] ?? '');
		$email = (string)($data['email'] ?? '');
		$exp = $data['exp'] ?? null;

		if (preg_match(self::UUID_PATTERN, $uuid) !== 1
			|| $email === ''
			|| filter_var($email, FILTER_VALIDATE_EMAIL) === false
			|| !is_int($exp)
			|| $exp <= ($now ?? time())
		) {
			return null;
		}

		return ['uuid' => $uuid, 'email' => $email];
	}
}
