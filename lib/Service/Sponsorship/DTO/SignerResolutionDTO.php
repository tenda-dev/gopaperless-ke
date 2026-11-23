<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Sponsorship\DTO;

/**
 * Represents the outcome of resolving a sign request's signer identity.
 *
 * A user ID is only provided for resolved identities. Unresolved results
 * must not fall back to the file owner, as this could settle an entitlement
 * against the wrong account.
 */
final class SignerResolutionDTO {
	private function __construct(
		public readonly bool $resolved,
		public readonly ?string $userId,
		public readonly ?string $reason,
	) {
	}

	public static function resolved(string $userId): self {
		return new self(true, $userId, null);
	}

	/**
	 * Creates a result representing an unresolved signer identity.
	 *
	 * @param string $reason Machine-readable, e.g. 'account_not_found',
	 *                       'email_unprovisioned', 'email_ambiguous', 'no_identity'.
	 */
	public static function unresolved(string $reason): self {
		return new self(false, null, $reason);
	}
}
