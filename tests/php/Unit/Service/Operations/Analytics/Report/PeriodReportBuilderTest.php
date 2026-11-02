<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Operations\Analytics\Report;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Libresign\Service\Operations\Analytics\AccountCountsProvider;
use OCA\Libresign\Service\Operations\Analytics\Mapper\OperationsAnalyticsMapper;
use OCA\Libresign\Service\Operations\Analytics\OperationsAnalyticsService;
use OCA\Libresign\Service\Operations\Analytics\Report\PeriodReportBuilder;
use OCA\Libresign\Service\Operations\Analytics\Report\PeriodSchedule;
use OCA\Libresign\Service\Operations\Analytics\Report\PeriodWindow;
use OCA\Libresign\Service\Operations\Analytics\ValueObject\DateRange;
use OCA\Libresign\Tests\Unit\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Uses the real OperationsAnalyticsService over a mocked mapper, as
 * OperationsAnalyticsServiceTest does, so the report is checked against the
 * same read model the dashboard uses.
 */
final class PeriodReportBuilderTest extends TestCase {
	private OperationsAnalyticsMapper&MockObject $mapper;
	private OperationsAnalyticsService $service;
	private PeriodReportBuilder $builder;
	private PeriodWindow $window;

	public function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(OperationsAnalyticsMapper::class);
		$this->mapper->method('accountIdentifierKey')->willReturn('account');
		// overview() indexes these keys, so an unstubbed (empty) mock would raise warnings.
		$this->mapper->method('signingWorkflowCounts')->willReturn(['numerator' => 0, 'denominator' => 0]);
		$accounts = $this->createMock(AccountCountsProvider::class);
		$accounts->method('counts')->willReturn(['lifetime' => 10, 'enabled' => 9]);
		$this->service = new OperationsAnalyticsService($this->mapper, $accounts);
		$this->builder = new PeriodReportBuilder($this->service);
		// Weekly default: reports 2026-10-19..2026-10-25 against 2026-10-12..2026-10-18.
		$this->window = PeriodWindow::latestDue(
			PeriodSchedule::default(),
			new DateTimeImmutable('2026-10-26T06:00:00Z', new DateTimeZone('UTC')),
		);
	}

	/**
	 * Returns $current items for the reported period and $previous for the preceding one.
	 */
	private function perPeriod(int $current, int $previous): \Closure {
		return fn (DateRange $range): array => array_fill(0, $range->from === $this->window->current->from ? $current : $previous, '2026-10-20 10:00:00');
	}

	public function testExtractsTheDashboardFiguresForBothPeriods(): void {
		$this->mapper->method('signatureTimes')->willReturnCallback($this->perPeriod(12, 10));
		$this->mapper->method('completedDocumentTimes')->willReturnCallback($this->perPeriod(5, 0));
		$this->mapper->method('revenueByCurrency')->willReturnCallback(
			fn (DateRange $range): array => $range->from === $this->window->current->from
				? [['currency' => 'KES', 'amountMinor' => 1000000], ['currency' => 'USD', 'amountMinor' => 5000]]
				: [['currency' => 'KES', 'amountMinor' => 800000]],
		);
		$this->mapper->method('requesterSponsorshipActivity')->willReturnCallback(
			fn (DateRange $range): array => ['records' => $range->from === $this->window->current->from ? 7 : 3, 'documents' => 99],
		);

		$report = $this->builder->build($this->window);

		$this->assertSame('weekly', $report['frequency']);
		$this->assertSame('Africa/Nairobi', $report['timezone']);
		$this->assertSame(['from' => '2026-10-19', 'to' => '2026-10-25'], $report['period']);
		$this->assertSame(['from' => '2026-10-12', 'to' => '2026-10-18'], $report['previousPeriod']);
		$this->assertSame(['KES' => 1000000, 'USD' => 5000], $report['current']['revenue']);
		$this->assertSame(['KES' => 800000], $report['previous']['revenue']);
		$this->assertSame(12, $report['current']['signatures']);
		$this->assertSame(10, $report['previous']['signatures']);
		$this->assertSame(5, $report['current']['documents']);
		$this->assertSame(0, $report['previous']['documents']);
		// Sponsorships come from requesterActivity.records, not .documents.
		$this->assertSame(7, $report['current']['sponsorships']);
		$this->assertSame(3, $report['previous']['sponsorships']);
	}

	public function testParityWithOverviewAndEntitlementsForTheSameRange(): void {
		$this->mapper->method('signatureTimes')->willReturnCallback($this->perPeriod(4, 2));
		$this->mapper->method('completedDocumentTimes')->willReturnCallback($this->perPeriod(3, 1));
		$this->mapper->method('revenueByCurrency')->willReturn([['currency' => 'KES', 'amountMinor' => 4200]]);
		$this->mapper->method('requesterSponsorshipActivity')->willReturn(['records' => 6, 'documents' => 2]);

		$report = $this->builder->build($this->window);
		$range = $this->window->current;
		$overview = $this->service->overview($range)['metrics'];
		$entitlements = $this->service->entitlements($range)['metrics'];

		$this->assertSame($overview['signaturesCollected']['value'], $report['current']['signatures']);
		$this->assertSame($overview['completedDocuments']['value'], $report['current']['documents']);
		$this->assertSame(
			array_column($overview['revenue']['byCurrency'], 'amountMinor', 'currency'),
			$report['current']['revenue'],
		);
		$this->assertSame($entitlements['sponsorshipMix']['requesterActivity']['records'], $report['current']['sponsorships']);
	}

	public function testRegisteredUsersIsTheDashboardLifetimeAccountCount(): void {
		$report = $this->builder->build($this->window);
		$overview = $this->service->overview($this->window->current)['metrics'];

		$this->assertSame(10, $report['current']['registeredUsers']);
		$this->assertSame($overview['registeredAccountsLifetime']['value'], $report['current']['registeredUsers']);
		$this->assertArrayNotHasKey('newAccounts', $report['current']);
	}

	public function testNoPaymentsIsAnEmptyMapNotUnavailable(): void {
		$this->mapper->method('revenueByCurrency')->willReturn([]);

		$report = $this->builder->build($this->window);

		$this->assertSame([], $report['current']['revenue']);
	}

	public function testMalformedRevenueRowsAreIgnored(): void {
		$this->mapper->method('revenueByCurrency')->willReturn([
			['currency' => 'KES', 'amountMinor' => 100],
			['currency' => 'USD'],
			['amountMinor' => 5],
			'junk',
		]);

		$this->assertSame(['KES' => 100], $this->builder->build($this->window)['current']['revenue']);
	}
}
