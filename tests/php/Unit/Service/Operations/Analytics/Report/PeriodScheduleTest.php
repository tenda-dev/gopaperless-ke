<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Operations\Analytics\Report;

use InvalidArgumentException;
use OCA\Libresign\Service\Operations\Analytics\Report\PeriodFrequency;
use OCA\Libresign\Service\Operations\Analytics\Report\PeriodSchedule;
use OCA\Libresign\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PeriodScheduleTest extends TestCase {
	public function testDefaultIsWeeklyMondayNineNairobi(): void {
		$default = PeriodSchedule::default();

		$this->assertSame(PeriodFrequency::WEEKLY, $default->frequency);
		$this->assertSame(1, $default->weekday);
		$this->assertSame(1, $default->dayOfMonth);
		$this->assertSame('09:00', $default->time);
		$this->assertSame('Africa/Nairobi', $default->timezone);
	}

	public function testEmptyArrayYieldsDefaults(): void {
		$this->assertSame(PeriodSchedule::default()->toArray(), PeriodSchedule::fromArray([])->toArray());
	}

	public function testValidArrayRoundTrips(): void {
		$input = ['frequency' => 'monthly', 'weekday' => 5, 'dayOfMonth' => 28, 'time' => '23:59', 'timezone' => 'Europe/Berlin'];

		$schedule = PeriodSchedule::fromArray($input);

		$this->assertSame($input, $schedule->toArray());
		$this->assertSame($input, PeriodSchedule::fromArray($schedule->toArray())->toArray());
	}

	public function testNumericStringsAreAcceptedForNumbers(): void {
		$schedule = PeriodSchedule::fromArray(['weekday' => '3', 'dayOfMonth' => '15']);

		$this->assertSame(3, $schedule->weekday);
		$this->assertSame(15, $schedule->dayOfMonth);
	}

	public static function invalidInputs(): array {
		return [
			'frequency unknown' => [['frequency' => 'hourly']],
			'frequency not string' => [['frequency' => 1]],
			'weekday 0' => [['weekday' => 0]],
			'weekday 8' => [['weekday' => 8]],
			'weekday float' => [['weekday' => 1.5]],
			'dayOfMonth 0' => [['dayOfMonth' => 0]],
			'dayOfMonth 29' => [['dayOfMonth' => 29]],
			'time 24:00' => [['time' => '24:00']],
			'time single digit hour' => [['time' => '9:00']],
			'time minute 60' => [['time' => '09:60']],
			'time with seconds' => [['time' => '09:00:00']],
			'time not string' => [['time' => 900]],
			'timezone unknown' => [['timezone' => 'Mars/Olympus']],
			'timezone empty' => [['timezone' => '']],
			'timezone offset style' => [['timezone' => '+03:00']],
			'not an array' => ['weekly'],
		];
	}

	#[DataProvider('invalidInputs')]
	public function testFromArrayRejectsInvalidInput(mixed $input): void {
		$this->expectException(InvalidArgumentException::class);

		PeriodSchedule::fromArray($input);
	}

	public static function junk(): array {
		return [
			'null' => [null],
			'string' => ['garbage'],
			'int' => [42],
			'empty array' => [[]],
			'wrong types' => [['frequency' => [], 'weekday' => 'x', 'dayOfMonth' => null, 'time' => false, 'timezone' => 7]],
			'unknown keys' => [['foo' => 'bar']],
		];
	}

	#[DataProvider('junk')]
	public function testFromStoredOrDefaultNeverThrowsAndFallsBackToDefaults(mixed $input): void {
		$this->assertSame(PeriodSchedule::default()->toArray(), PeriodSchedule::fromStoredOrDefault($input)->toArray());
	}

	public function testFromStoredOrDefaultKeepsValidFieldsAndDefaultsTheRest(): void {
		$schedule = PeriodSchedule::fromStoredOrDefault([
			'frequency' => 'daily',
			'weekday' => 99,
			'time' => '07:30',
			'timezone' => 'Nowhere/Land',
		]);

		$this->assertSame(PeriodFrequency::DAILY, $schedule->frequency);
		$this->assertSame(1, $schedule->weekday);
		$this->assertSame('07:30', $schedule->time);
		$this->assertSame('Africa/Nairobi', $schedule->timezone);
	}
}
