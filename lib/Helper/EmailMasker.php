<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Helper;

/**
 * Masks email addresses for display during account recovery.
 *
 * Returns null when an address cannot be masked confidently. The full
 * address is never logged or included in the masked output.
 *
 * The local part and domain are partially masked, while the public
 * suffix is retained to help users recognise the intended address.
 */
final class EmailMasker {
	public const DOTS = '•••••';

	private const MAX_LENGTH = 254;
	private const MAX_LOCAL_LENGTH = 64;
	private const LOCAL_PATTERN = '/^[A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]+$/';
	private const DOMAIN_PATTERN = '/^[A-Za-z0-9.-]+$/';
	private const SECOND_LEVEL_LABELS = ['co', 'com', 'org', 'net', 'gov', 'edu', 'ac', 'or', 'go', 'ne', 'sc', 'me'];

	public static function mask(?string $email): ?string {
		if ($email === null) {
			return null;
		}
		$email = trim($email);
		if ($email === '' || strlen($email) > self::MAX_LENGTH) {
			return null;
		}
		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			return null;
		}

		$at = strrpos($email, '@');
		if ($at === false) {
			return null;
		}
		$local = substr($email, 0, $at);
		$domain = substr($email, $at + 1);
		if (strlen($local) > self::MAX_LOCAL_LENGTH
			|| preg_match(self::LOCAL_PATTERN, $local) !== 1
			|| preg_match(self::DOMAIN_PATTERN, $domain) !== 1
		) {
			return null;
		}

		$labels = explode('.', $domain);
		if (count($labels) < 2 || in_array('', $labels, true)) {
			return null;
		}
		if (ctype_digit($labels[count($labels) - 1])) {
			return null;
		}

		return self::maskLocal($local) . '@' . $labels[0][0] . self::DOTS . '.' . implode('.', self::suffix($labels));
	}

	private static function maskLocal(string $local): string {
		$plus = strpos($local, '+');
		$base = $plus === false ? $local : substr($local, 0, $plus);
		$keep = min(2, intdiv(strlen($base), 2));

		return substr($base, 0, $keep) . self::DOTS;
	}

	/**
	 * @param string[] $labels at least two
	 * @return string[]
	 */
	private static function suffix(array $labels): array {
		$count = count($labels);
		if ($count >= 3
			&& preg_match('/^[A-Za-z]{2}$/', $labels[$count - 1]) === 1
			&& in_array(strtolower($labels[$count - 2]), self::SECOND_LEVEL_LABELS, true)
		) {
			return array_slice($labels, -2);
		}

		return array_slice($labels, -1);
	}
}
