<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Enum;

enum PaymentProvider: string
{
	case DPO = 'dpo';
	case DARAJA = 'daraja';

	public function isVerifiable(): bool
	{
		return match ($this) {
			self::DPO => true,
			self::DARAJA => false,
		};
	}

	/**
	 * Helper for DB queries
	 */
	public static function verifiableValues(): array
	{
		return array_map(
			fn(self $p) => $p->value,
			array_filter(self::cases(), fn(self $p) => $p->isVerifiable())
		);
	}
}
