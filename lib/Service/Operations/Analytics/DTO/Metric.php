<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Operations\Analytics\DTO;

/**
 * Builders for metric shapes in the API contract. Unavailable metrics are
 * represented as `{available:false, reason}` rather than as zero values.
 */
final class Metric {
	public const GRAIN_PERIOD = 'period';
	public const GRAIN_POINT_IN_TIME = 'point_in_time';

	/**
	 * Builds a value metric.
	 *
	 * `confirmed: false` marks an estimate rather than a figure calculated
	 * directly from existing records.
	 *
	 * @return array{value: int, grain: string, confirmed: bool}
	 */
	public static function value(int $value, string $grain, bool $confirmed = true): array {
		return ['value' => $value, 'grain' => $grain, 'confirmed' => $confirmed];
	}

	/**
	 * @return array{available: false, reason: string}
	 */
	public static function unavailable(string $reason): array {
		return ['available' => false, 'reason' => $reason];
	}

	/**
	 * Builds a success-rate metric. A zero denominator produces a null rate,
	 * not 0%, because no terminal outcomes means there is no rate to report.
	 * The unit identifies what is counted; rates with different units are
	 * not directly comparable.
	 *
	 * @return array{grain: string, unit: string, numerator: int, denominator: int, rate: ?float}
	 */
	public static function rate(int $numerator, int $denominator, string $unit): array {
		return [
			'grain' => self::GRAIN_PERIOD,
			'unit' => $unit,
			'numerator' => $numerator,
			'denominator' => $denominator,
			'rate' => $denominator > 0 ? round($numerator / $denominator, 4) : null,
		];
	}
}
