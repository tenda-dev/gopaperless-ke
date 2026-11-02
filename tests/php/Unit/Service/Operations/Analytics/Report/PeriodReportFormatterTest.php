<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Operations\Analytics\Report;

use OCA\Libresign\Service\Operations\Analytics\Report\PeriodFrequency;
use OCA\Libresign\Service\Operations\Analytics\Report\PeriodReportFormatter as F;
use OCA\Libresign\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PeriodReportFormatterTest extends TestCase {
	public static function deltaCases(): array {
		return [
			'both zero' => [0, 0, F::DELTA_NO_CHANGE, 0, null],
			'equal non-zero' => [5, 5, F::DELTA_NO_CHANGE, 0, 0],
			'up from zero' => [7, 0, F::DELTA_UP_FROM_ZERO, 7, null],
			'increase' => [12, 10, F::DELTA_CHANGE, 2, 20],
			'decrease' => [8, 10, F::DELTA_CHANGE, -2, -20],
			'drops to zero' => [0, 10, F::DELTA_CHANGE, -10, -100],
			'rounds half up' => [3, 2, F::DELTA_CHANGE, 1, 50],
			'rounds to nearest' => [1002, 1000, F::DELTA_CHANGE, 2, 0],
			'current unavailable' => [null, 4, F::DELTA_NA, 0, null],
			'previous unavailable' => [4, null, F::DELTA_NA, 0, null],
			'both unavailable' => [null, null, F::DELTA_NA, 0, null],
		];
	}

	#[DataProvider('deltaCases')]
	public function testDelta(?int $current, ?int $previous, string $kind, int $absolute, ?int $percent): void {
		$delta = F::delta($current, $previous);

		$this->assertSame($kind, $delta['kind']);
		$this->assertSame($absolute, $delta['absolute']);
		$this->assertSame($percent, $delta['percent']);
	}

	public function testDeltaNeverProducesNanOrInf(): void {
		foreach ([[0, 0], [1, 0], [0, 1], [PHP_INT_MAX, 1], [1, PHP_INT_MAX]] as [$current, $previous]) {
			$percent = F::delta($current, $previous)['percent'];
			$this->assertTrue($percent === null || is_int($percent));
		}
	}

	public static function moneyCases(): array {
		return [
			'KES two decimals' => [17334200, 'KES', 'KES 173,342.00'],
			'KES small' => [5, 'KES', 'KES 0.05'],
			'KES zero' => [0, 'KES', 'KES 0.00'],
			'XOF no decimals' => [5000, 'XOF', 'XOF 5,000'],
			'TZS no decimals' => [1234567, 'TZS', 'TZS 1,234,567'],
			'unknown currency defaults to two decimals' => [12345, 'ABC', 'ABC 123.45'],
			'negative' => [-1050, 'USD', '-USD 10.50'],
		];
	}

	#[DataProvider('moneyCases')]
	public function testMoney(int $minor, string $currency, string $expected): void {
		$this->assertSame($expected, F::money($minor, $currency));
	}

	public function testRevenueComparisonUsesTheUnionOfCurrenciesAndNeverSums(): void {
		$rows = F::revenueComparison(['KES' => 1000, 'USD' => 500], ['KES' => 800, 'XOF' => 300]);

		$this->assertSame(['KES', 'USD', 'XOF'], array_column($rows, 'currency'));
		$byCurrency = array_column($rows, null, 'currency');
		$this->assertSame(1000, $byCurrency['KES']['current']);
		$this->assertSame(800, $byCurrency['KES']['previous']);
		$this->assertSame(F::DELTA_UP_FROM_ZERO, $byCurrency['USD']['delta']['kind']);
		$this->assertSame(0, $byCurrency['XOF']['current']);
		$this->assertSame(-100, $byCurrency['XOF']['delta']['percent']);
	}

	public function testRevenueComparisonUnavailableCurrentIsNull(): void {
		$this->assertNull(F::revenueComparison(null, ['KES' => 1]));
	}

	public function testRevenueComparisonUnavailablePreviousGivesNaDeltas(): void {
		$rows = F::revenueComparison(['KES' => 1000], null);

		$this->assertCount(1, $rows);
		$this->assertNull($rows[0]['previous']);
		$this->assertSame(F::DELTA_NA, $rows[0]['delta']['kind']);
	}

	public function testRevenueComparisonEmptyBothSides(): void {
		$this->assertSame([], F::revenueComparison([], []));
	}

	public function testPeriodLabel(): void {
		$this->assertSame('2026-10-28', F::periodLabel('2026-10-28', '2026-10-28'));
		$this->assertSame('2026-10-19 – 2026-10-25', F::periodLabel('2026-10-19', '2026-10-25'));
	}

	public function testPreviousUnitByFrequency(): void {
		$this->assertSame('day', F::previousUnit(PeriodFrequency::DAILY));
		$this->assertSame('week', F::previousUnit(PeriodFrequency::WEEKLY));
		$this->assertSame('month', F::previousUnit(PeriodFrequency::MONTHLY));
	}
}
