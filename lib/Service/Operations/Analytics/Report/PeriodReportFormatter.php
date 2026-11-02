<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Operations\Analytics\Report;

use OCA\Libresign\Service\Payment\CurrencyConfig;

/**
 * Pure presentation helpers for the period report. No I/O, no translation:
 * results are structured so the mailer can localise the wording.
 */
final class PeriodReportFormatter {
	public const DELTA_NA = 'na';
	public const DELTA_NO_CHANGE = 'no_change';
	public const DELTA_UP_FROM_ZERO = 'up_from_zero';
	public const DELTA_CHANGE = 'change';

	/**
	 * Formats minor units using the currency's decimals, e.g. KES 1,733.42.
	 * Locale independent; the currency is never converted.
	 */
	public static function money(int $amountMinor, string $currency): string {
		$decimals = CurrencyConfig::decimals($currency);
		$factor = 10 ** $decimals;
		$abs = abs($amountMinor);
		$whole = intdiv($abs, $factor);
		$text = number_format($whole, 0, '.', ',');
		if ($decimals > 0) {
			$text .= '.' . str_pad((string)($abs % $factor), $decimals, '0', STR_PAD_LEFT);
		}
		return ($amountMinor < 0 ? '-' : '') . $currency . ' ' . $text;
	}

	/**
	 * Compares two counts (or minor-unit amounts).
	 *
	 * @return array{kind: string, absolute: int, percent: int|null}
	 */
	public static function delta(?int $current, ?int $previous): array {
		if ($current === null || $previous === null) {
			return ['kind' => self::DELTA_NA, 'absolute' => 0, 'percent' => null];
		}
		$absolute = $current - $previous;
		if ($current === 0 && $previous === 0) {
			return ['kind' => self::DELTA_NO_CHANGE, 'absolute' => 0, 'percent' => null];
		}
		if ($previous === 0) {
			return ['kind' => self::DELTA_UP_FROM_ZERO, 'absolute' => $absolute, 'percent' => null];
		}
		if ($absolute === 0) {
			return ['kind' => self::DELTA_NO_CHANGE, 'absolute' => 0, 'percent' => 0];
		}
		return [
			'kind' => self::DELTA_CHANGE,
			'absolute' => $absolute,
			'percent' => (int)round($absolute / abs($previous) * 100),
		];
	}

	/**
	 * Revenue compared per currency over the union of both periods' currencies
	 * (a currency missing on one side counts as 0 there). Currencies are never
	 * summed or converted. A null side means revenue was unavailable.
	 *
	 * @param array<string, int>|null $current
	 * @param array<string, int>|null $previous
	 * @return list<array{currency: string, current: int, previous: int|null, delta: array{kind: string, absolute: int, percent: int|null}}>|null
	 */
	public static function revenueComparison(?array $current, ?array $previous): ?array {
		if ($current === null) {
			return null;
		}
		$currencies = array_unique(array_merge(array_keys($current), array_keys($previous ?? [])));
		sort($currencies);
		$rows = [];
		foreach ($currencies as $currency) {
			$currency = (string)$currency;
			$cur = $current[$currency] ?? 0;
			$prev = $previous === null ? null : ($previous[$currency] ?? 0);
			$rows[] = [
				'currency' => $currency,
				'current' => $cur,
				'previous' => $prev,
				'delta' => self::delta($cur, $prev),
			];
		}
		return $rows;
	}

	/** ISO date, or a `from – to` range for multi-day periods; avoids locale-specific month names. */
	public static function periodLabel(string $from, string $to): string {
		return $from === $to ? $from : $from . ' – ' . $to;
	}

	/**
	 * Untranslated key for the comparison period wording.
	 *
	 * @return 'day'|'week'|'month'
	 */
	public static function previousUnit(PeriodFrequency $frequency): string {
		return match ($frequency) {
			PeriodFrequency::DAILY => 'day',
			PeriodFrequency::WEEKLY => 'week',
			PeriodFrequency::MONTHLY => 'month',
		};
	}
}
