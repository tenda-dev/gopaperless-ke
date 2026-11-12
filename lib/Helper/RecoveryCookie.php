<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Helper;

use OCP\IConfig;

/**
 * Issues and verifies a stateless, signed cookie for account recovery.
 *
 * The cookie carries a signing destination and an account binding through
 * authentication without storing the account's raw UID or email.
 *
 * Its purpose is navigation only. It never authorizes signing; the signing
 * page's existing authorization gate remains authoritative.
 *
 * Invalid input, expired cookies and unavailable signing secrets are
 * treated as an invalid cookie. Rotating the instance secret invalidates
 * outstanding cookies.
 *
 * Format: v1~uuid~expiry~uid_tag.signature
 */
class RecoveryCookie {
	public const NAME = 'gp_recover';
	// Allow time for account reauthentication while limiting how long this
	// navigation-only recovery destination remains valid.
	public const TTL = 600;

	private const VERSION = '1';
	private const MAX_LENGTH = 200;
	private const UID_PREFIX = 'gopaperless-ws1-recovery-uid:';
	private const SIG_PREFIX = 'gopaperless-ws1-recovery-sig:';
	private const UUID_PATTERN = '/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD';
	// SHA-256 is 32 bytes: 43 characters of unpadded base64url.
	private const MAC_PATTERN = '/^[A-Za-z0-9_-]{43}$/D';
	private const EXP_PATTERN = '/^[0-9]{1,10}$/D';

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * @param ?int $now Seconds since epoch; only for deterministic tests.
	 * @return ?string The cookie value, or null when it cannot be issued safely.
	 */
	public function issue(string $uuid, string $intendedUid, ?int $now = null): ?string {
		$secret = $this->secret();
		if ($secret === '' || $intendedUid === '' || preg_match(self::UUID_PATTERN, $uuid) !== 1) {
			return null;
		}
		$exp = ($now ?? time()) + self::TTL;
		$body = implode('~', [self::VERSION, $uuid, (string)$exp, $this->uidTag($uuid, $intendedUid, $secret)]);

		return $body . '.' . $this->mac(self::SIG_PREFIX . $body, $secret);
	}

	/**
	 * @param ?int $now Seconds since epoch; only for deterministic tests.
	 * @return ?array{uuid: string, uidTag: string} Null for anything that is not a
	 *         well-formed, correctly signed, unexpired cookie.
	 */
	public function verify(?string $raw, ?int $now = null): ?array {
		$secret = $this->secret();
		if ($secret === '' || $raw === null || $raw === '' || strlen($raw) > self::MAX_LENGTH) {
			return null;
		}

		$parts = explode('.', $raw);
		if (count($parts) !== 2) {
			return null;
		}
		[$body, $sig] = $parts;
		if (preg_match(self::MAC_PATTERN, $sig) !== 1
			|| !hash_equals($this->mac(self::SIG_PREFIX . $body, $secret), $sig)
		) {
			return null;
		}

		$fields = explode('~', $body);
		if (count($fields) !== 4) {
			return null;
		}
		[$version, $uuid, $exp, $uidTag] = $fields;
		if ($version !== self::VERSION
			|| preg_match(self::EXP_PATTERN, $exp) !== 1
			|| (int)$exp <= ($now ?? time())
			|| preg_match(self::UUID_PATTERN, $uuid) !== 1
			|| preg_match(self::MAC_PATTERN, $uidTag) !== 1
		) {
			return null;
		}

		return ['uuid' => $uuid, 'uidTag' => $uidTag];
	}

	/**
	 * Determines whether recovery may return to the signing page for this
	 * authenticated account. It never grants permission to sign.
	 *
	 * @param array{uuid: string, uidTag: string} $verified Result of verify()
	 */
	public function isBoundTo(array $verified, string $uid): bool {
		$secret = $this->secret();
		if ($secret === '' || $uid === '') {
			return false;
		}

		return hash_equals($this->uidTag($verified['uuid'], $uid, $secret), $verified['uidTag']);
	}

	private function uidTag(string $uuid, string $uid, string $secret): string {
		return $this->mac(self::UID_PREFIX . $uuid . ':' . $uid, $secret);
	}

	private function mac(string $data, string $secret): string {
		return rtrim(strtr(base64_encode(hash_hmac('sha256', $data, $secret, true)), '+/', '-_'), '=');
	}

	private function secret(): string {
		return $this->config->getSystemValueString('secret');
	}
}
