<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Operations\Analytics\Report;

use OCA\Libresign\Service\Operations\Analytics\OperationsAnalyticsService;
use OCA\Libresign\Service\Operations\Analytics\ValueObject\DateRange;

/**
 * Extracts the email figures from the existing analytics read model.
 *
 * No SQL and no recomputation: every number comes from
 * OperationsAnalyticsService::overview() / entitlements() for the reported
 * period and the preceding period. A metric that is unavailable or missing
 * is reported as null ("not available"), never as zero.
 *
 * @psalm-type PeriodSnapshot = array{
 *     revenue: array<string, int>|null,
 *     signatures: int|null,
 *     documents: int|null,
 *     sponsorships: int|null,
 *     registeredUsers: int|null,
 * }
 */
class PeriodReportBuilder {
	public function __construct(
		private OperationsAnalyticsService $analytics,
	) {
	}

	/**
	 * @return array{
	 *     frequency: string,
	 *     timezone: string,
	 *     period: array{from: string, to: string},
	 *     previousPeriod: array{from: string, to: string},
	 *     current: PeriodSnapshot,
	 *     previous: PeriodSnapshot,
	 * }
	 */
	public function build(PeriodWindow $window): array {
		return [
			'frequency' => $window->schedule->frequency->value,
			'timezone' => $window->schedule->timezone,
			'period' => ['from' => $window->current->from, 'to' => $window->current->to],
			'previousPeriod' => ['from' => $window->previous->from, 'to' => $window->previous->to],
			'current' => $this->snapshot($window->current),
			'previous' => $this->snapshot($window->previous),
		];
	}

	/**
	 * @return PeriodSnapshot
	 */
	private function snapshot(DateRange $range): array {
		$overview = $this->analytics->overview($range);
		$entitlements = $this->analytics->entitlements($range);
		$overviewMetrics = is_array($overview['metrics'] ?? null) ? $overview['metrics'] : [];
		$entitlementMetrics = is_array($entitlements['metrics'] ?? null) ? $entitlements['metrics'] : [];

		return [
			'revenue' => $this->revenue($overviewMetrics['revenue']['byCurrency'] ?? null),
			'signatures' => $this->metricValue($overviewMetrics['signaturesCollected'] ?? null),
			'documents' => $this->metricValue($overviewMetrics['completedDocuments'] ?? null),
			// Requester sponsorship RECORDS created in the period, not completed
			// signings or credits consumed.
			'sponsorships' => $this->plainInt($entitlementMetrics['sponsorshipMix']['requesterActivity']['records'] ?? null),
			// Point-in-time count of existing accounts when the report is generated
			// (the period has no registration timestamp to count new accounts by).
			'registeredUsers' => $this->metricValue($overviewMetrics['registeredAccountsLifetime'] ?? null),
		];
	}

	/**
	 * @return array<string, int>|null null = unavailable, [] = no revenue
	 */
	private function revenue(mixed $rows): ?array {
		if (!is_array($rows)) {
			return null;
		}
		$byCurrency = [];
		foreach ($rows as $row) {
			if (!is_array($row) || !is_string($row['currency'] ?? null) || !is_int($row['amountMinor'] ?? null)) {
				continue;
			}
			$byCurrency[$row['currency']] = ($byCurrency[$row['currency']] ?? 0) + $row['amountMinor'];
		}
		ksort($byCurrency);
		return $byCurrency;
	}

	private function metricValue(mixed $metric): ?int {
		if (!is_array($metric) || ($metric['available'] ?? true) === false) {
			return null;
		}
		return $this->plainInt($metric['value'] ?? null);
	}

	private function plainInt(mixed $value): ?int {
		return is_int($value) ? $value : null;
	}
}
