<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Operations\Analytics;

use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Service\Operations\Analytics\AccountCountsProvider;
use OCA\Libresign\Service\Operations\Analytics\Exception\InvalidRangeException;
use OCA\Libresign\Service\Operations\Analytics\Mapper\OperationsAnalyticsMapper;
use OCA\Libresign\Service\Operations\Analytics\OperationsAnalyticsService;
use OCA\Libresign\Service\Operations\Analytics\ValueObject\DateRange;
use OCA\Libresign\Tests\Unit\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

final class OperationsAnalyticsServiceTest extends TestCase {
	private OperationsAnalyticsMapper&MockObject $mapper;
	private AccountCountsProvider&MockObject $accounts;
	private OperationsAnalyticsService $service;
	private DateRange $range;

	public function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(OperationsAnalyticsMapper::class);
		$this->mapper->method('accountIdentifierKey')->willReturn('account');
		$this->accounts = $this->createMock(AccountCountsProvider::class);
		$this->accounts->method('counts')->willReturn(['lifetime' => 1284, 'enabled' => 1192]);
		$this->service = new OperationsAnalyticsService($this->mapper, $this->accounts);
		$this->range = DateRange::fromRequest('2026-10-27', '2026-10-29');
	}

	private function stubPayments(array $sign, array $credit, array $workflow): void {
		$this->mapper->method('paymentStatusCounts')->willReturnCallback(
			static fn (PaymentPurpose $p): array => $p === PaymentPurpose::SIGN_REQUEST ? $sign : $credit,
		);
		$this->mapper->method('signingWorkflowCounts')->willReturn($workflow);
	}

	public function testAttemptAndWorkflowSuccessAreSeparateFigures(): void {
		// One workflow: a failed attempt then a paid attempt => attempts 1/2, workflow 1/1.
		$this->stubPayments(
			['paid' => 1, 'failed' => 1, 'pending' => 4, 'initiation_failed' => 3],
			['paid' => 0, 'expired' => 1, 'pending' => 2],
			['numerator' => 1, 'denominator' => 1],
		);

		$m = $this->service->payments($this->range)['metrics'];

		$this->assertSame(['grain' => 'period', 'unit' => 'payment_attempt', 'numerator' => 1, 'denominator' => 2, 'rate' => 0.5], $m['signingPaymentSuccess']);
		$this->assertSame(['grain' => 'period', 'unit' => 'workflow', 'numerator' => 1, 'denominator' => 1, 'rate' => 1.0], $m['signingWorkflowSuccess']);
		$this->assertSame('payment_attempt', $m['creditPurchaseSuccess']['unit']);
		$this->assertSame(0, $m['creditPurchaseSuccess']['numerator']);
		$this->assertSame(1, $m['creditPurchaseSuccess']['denominator']);
	}

	public function testRateIsNullNotZeroWhenNoTerminalAttempts(): void {
		$this->stubPayments(['pending' => 5, 'initiation_failed' => 2], [], ['numerator' => 0, 'denominator' => 0]);

		$m = $this->service->payments($this->range)['metrics'];

		$this->assertNull($m['signingPaymentSuccess']['rate']);
		$this->assertSame(0, $m['signingPaymentSuccess']['denominator']);
		$this->assertNull($m['signingWorkflowSuccess']['rate']);
	}

	public function testRevenueStaysPerCurrencyInMinorUnits(): void {
		$this->stubPayments([], [], ['numerator' => 0, 'denominator' => 0]);
		$rows = [['currency' => 'KES', 'amountMinor' => 17334200], ['currency' => 'USD', 'amountMinor' => 5000]];
		$this->mapper->method('revenueByCurrency')->willReturn($rows);

		$revenue = $this->service->payments($this->range)['metrics']['revenue'];

		$this->assertSame($rows, $revenue['byCurrency']);
		$this->assertSame('paid_at', $revenue['timeField']);
	}

	public function testOverviewMarksUnavailableMetricsInsteadOfZero(): void {
		$this->stubPayments([], [], ['numerator' => 0, 'denominator' => 0]);

		$m = $this->service->overview($this->range)['metrics'];

		$this->assertSame(['available' => false, 'reason' => 'no_registration_timestamp'], $m['newRegistrations']);
		// creditConsumption is live now (F2); only newRegistrations stays unavailable here.
		$this->assertArrayNotHasKey('available', $m['creditConsumption']);
		$this->assertSame(0, $m['creditConsumption']['total']);
		$this->assertSame(1284, $m['registeredAccountsLifetime']['value']);
		$this->assertSame(1192, $m['enabledAccounts']['value']);
		$this->assertSame('point_in_time', $m['registeredAccountsLifetime']['grain']);
	}

	public function testOverviewAccountCountsUnavailableWhenBackendCannotCount(): void {
		$accounts = $this->createMock(AccountCountsProvider::class);
		$accounts->method('counts')->willReturn(['lifetime' => null, 'enabled' => null]);
		$service = new OperationsAnalyticsService($this->mapper, $accounts);
		$this->stubPayments([], [], ['numerator' => 0, 'denominator' => 0]);

		$m = $service->overview($this->range)['metrics'];

		$this->assertFalse($m['registeredAccountsLifetime']['available']);
		$this->assertFalse($m['enabledAccounts']['available']);
	}

	public function testOverviewSignerFigures(): void {
		$this->stubPayments([], [], ['numerator' => 0, 'denominator' => 0]);
		$this->mapper->method('distinctSigners')->willReturnCallback(
			static fn (DateRange $r, string $key): int => $key === 'account' ? 10 : 4,
		);
		$this->mapper->method('signatureTimes')->willReturn(['2026-10-28 10:00:00', '2026-10-28 11:00:00']);
		$this->mapper->method('completedDocumentTimes')->willReturn(['2026-10-28 11:00:00']);

		$m = $this->service->overview($this->range)['metrics'];

		$this->assertSame(10, $m['usersWhoSigned']['value']);
		$this->assertSame(14, $m['distinctSignersInclEmail']['value']);
		$this->assertFalse($m['distinctSignersInclEmail']['confirmed']);
		$this->assertTrue($m['distinctSignersInclEmail']['upperBound']);
		$this->assertSame(2, $m['signaturesCollected']['value']);
		$this->assertSame(1, $m['completedDocuments']['value']);
	}

	public function testTimeseriesBucketsByEatDayAndZeroFills(): void {
		// 23:30Z on Oct 28 is Oct 29 in Nairobi; Oct 27 has no activity.
		$this->mapper->method('signatureTimes')->willReturn(['2026-10-28 23:30:00', '2026-10-28 09:00:00', '2026-10-28 10:00:00']);

		$out = $this->service->timeseries($this->range, ['signing']);

		$this->assertSame([
			['date' => '2026-10-27', 'count' => 0],
			['date' => '2026-10-28', 'count' => 2],
			['date' => '2026-10-29', 'count' => 1],
		], $out['series']['signatures']);
		$this->assertArrayNotHasKey('paymentAttempts', $out['series']);
	}

	public function testTimeseriesPaymentsSplitsPaidFromAttempts(): void {
		$this->mapper->method('paymentAttemptTimes')->willReturn([
			['at' => '2026-10-28 09:00:00', 'status' => 'paid'],
			['at' => '2026-10-28 09:05:00', 'status' => 'failed'],
			['at' => '2026-10-28 09:10:00', 'status' => 'pending'],
		]);

		$s = $this->service->timeseries($this->range, ['payments'])['series'];

		$this->assertSame(3, $s['paymentAttempts'][1]['count']);
		$this->assertSame(1, $s['paymentsPaid'][1]['count']);
	}

	public function testTimeseriesNeverAcceptsRegistrations(): void {
		foreach (['registrations', 'bogus'] as $bad) {
			try {
				$this->service->timeseries($this->range, [$bad]);
				$this->fail("expected rejection of $bad");
			} catch (InvalidRangeException $e) {
				$this->assertSame('invalid_params', $e->reason);
			}
		}
	}

	public function testTimeseriesAlwaysReportsRegistrationsUnavailable(): void {
		$series = $this->service->timeseries($this->range, [])['series'];
		$this->assertSame(['available' => false, 'reason' => 'no_registration_timestamp'], $series['newRegistrations']);
	}

	public function testFunnelHasApprovedThreeStagesWithDistinctUnits(): void {
		// created roots by status: draft 5, able_to_sign 10, partial 4, signed 6, deleted 3, signing_in_progress 1
		$this->mapper->method('createdDocumentsByStatus')->willReturn([0 => 5, 1 => 10, 2 => 4, 3 => 6, 4 => 3, 5 => 1]);
		$this->mapper->method('signatureTimes')->willReturn(['a', 'b', 'c']);
		$this->mapper->method('completedDocumentTimes')->willReturn(['x', 'y']);
		$this->mapper->method('publicWorkflowsByStatus')->willReturn(['claimed' => 2]);

		$f = $this->service->funnel($this->range);

		$this->assertTrue($f['unitsDiffer']);
		$this->assertSame(['workflowsInitiated', 'signaturesCompleted', 'fullyCompletedDocuments'], array_column($f['stages'], 'key'));
		$byKey = [];
		foreach ($f['stages'] as $stage) {
			$byKey[$stage['key']] = $stage;
		}
		// Initiated = sent-or-further roots (able_to_sign + partial + signed + signing_in_progress); drafts and deleted excluded.
		$this->assertSame(21, $byKey['workflowsInitiated']['count']);
		$this->assertSame('documents', $byKey['workflowsInitiated']['unit']);
		$this->assertSame(3, $byKey['signaturesCompleted']['count']);
		$this->assertSame('signatures', $byKey['signaturesCompleted']['unit']);
		$this->assertSame(2, $byKey['fullyCompletedDocuments']['count']);
		$this->assertSame('documents', $byKey['fullyCompletedDocuments']['unit']);
		$this->assertSame(['claimed' => 2], $f['publicUpload']['byStatus']);
		// The unapproved document-status cohort is gone.
		$this->assertArrayNotHasKey('deleted', $f);
		$this->assertArrayNotHasKey('drafts', $f);
	}

	public function testEntitlementsSumBalancesAndFlagUnlimited(): void {
		$this->mapper->method('entitlementBalances')->willReturn([
			['productCode' => 'SIGN_DOCUMENT', 'remaining' => 100, 'reserved' => 10, 'unlimited' => 1],
			['productCode' => 'OTHER', 'remaining' => 50, 'reserved' => 0, 'unlimited' => 0],
		]);
		$this->mapper->method('grantsByType')->willReturn(['ADMIN_GRANTED' => 3]);

		$m = $this->service->entitlements($this->range)['metrics'];

		$this->assertSame(150, $m['availableCredits']['remaining']);
		$this->assertSame(10, $m['availableCredits']['reserved']);
		$this->assertSame(140, $m['availableCredits']['available']);
		$this->assertSame(1, $m['availableCredits']['unlimitedEntitlements']);
		$this->assertSame(['ADMIN_GRANTED' => 3], $m['creditGrants']['byType']);
		// Flipped from {available:false, reason:'deferred_v1_scope'}: now populated (F2).
		$this->assertArrayNotHasKey('available', $m['creditConsumption']);
		$this->assertSame(0, $m['creditConsumption']['total']);
	}

	public function testCreditConsumptionGroupsByEntitlementTypeAndLabelsUnresolvablePools(): void {
		// entitlement 11 = PAYG (3 signings), 12 = ADMIN_GRANTED (1), 99 = deleted entitlement (2), 0 = marker without entitlementId (1)
		$this->mapper->method('consumedByEntitlement')->willReturn([11 => 3, 12 => 1, 99 => 2, 0 => 1]);
		$this->mapper->method('entitlementTypes')->willReturnCallback(
			static fn (array $ids): array => array_intersect_key([11 => 'PAY_AS_YOU_GO', 12 => 'ADMIN_GRANTED'], array_flip($ids)),
		);

		$c = $this->service->entitlements($this->range)['metrics']['creditConsumption'];

		$this->assertSame(
			['grain' => 'period', 'unit' => 'credits', 'timeField' => 'signed', 'basis' => 'one_credit_per_settled_signing', 'total' => 7,
				'byType' => ['ADMIN_GRANTED' => 1, 'PAY_AS_YOU_GO' => 3, 'UNKNOWN' => 3]],
			$c,
		);
	}

	public function testRequesterActivityIsLabelledAsCreationAndKeepsTheExistingMixShape(): void {
		$this->mapper->method('sponsorshipMix')->willReturn(['requester' => 5, 'self' => 2]);
		$this->mapper->method('requesterSponsorshipActivity')->willReturn(['records' => 5, 'documents' => 3]);

		$mix = $this->service->entitlements($this->range)['metrics']['sponsorshipMix'];

		$this->assertSame(['requester' => 5, 'self' => 2], $mix['byType']);
		$this->assertSame('period', $mix['grain']);
		$this->assertSame(['label' => 'sponsorships_created', 'records' => 5, 'documents' => 3], $mix['requesterActivity']);
	}

	public function testFundingMixCountsARootOncePerCategoryAndSaysItOverlaps(): void {
		$meta = static fn (?int $id): string => json_encode(['entitlement_consumed' => true] + ($id === null ? [] : ['entitlementId' => $id]));
		$this->mapper->method('completedRootIds')->willReturn([1, 2, 3]);
		$this->mapper->method('signRequestsOfRoots')->willReturn([
			// root 1: two self signers, same pool + one requester signer  => self(1) + requester(1)
			['rootId' => 1, 'signRequestId' => 101, 'sponsorshipType' => 'self', 'metadata' => $meta(11)],
			['rootId' => 1, 'signRequestId' => 102, 'sponsorshipType' => 'self', 'metadata' => $meta(11)],
			['rootId' => 1, 'signRequestId' => 103, 'sponsorshipType' => 'requester', 'metadata' => $meta(12)],
			// root 2: direct pay (paid payment on 201), and a legacy self row with nothing => directPay(1) + unknown(1)
			['rootId' => 2, 'signRequestId' => 201, 'sponsorshipType' => 'self', 'metadata' => null],
			['rootId' => 2, 'signRequestId' => 202, 'sponsorshipType' => 'self', 'metadata' => '{}'],
			// root 3: malformed metadata + marker with no entitlementId => unknown(1) + self(1), pool UNKNOWN
			['rootId' => 3, 'signRequestId' => 301, 'sponsorshipType' => 'self', 'metadata' => '{"entitlement_consumed":tru'],
			['rootId' => 3, 'signRequestId' => 302, 'sponsorshipType' => 'self', 'metadata' => $meta(null)],
		]);
		$this->mapper->method('signRequestsWithPaidPayment')->willReturnCallback(
			static fn (array $ids): array => array_intersect_key([201 => true, 999 => true], array_flip($ids)),
		);
		$this->mapper->method('entitlementTypes')->willReturn([11 => 'PROMOTIONAL', 12 => 'PAY_AS_YOU_GO']);

		$f = $this->service->entitlements($this->range)['metrics']['funding'];

		$this->assertSame(3, $f['completedDocuments']);
		$this->assertSame('documents', $f['unit']);
		$this->assertSame('completion', $f['dateField']);
		$this->assertTrue($f['multiBucket']);
		$this->assertSame(
			['requesterSponsored' => 1, 'selfEntitlement' => 2, 'directPay' => 1, 'unknown' => 2],
			$f['categories'],
		);
		// 6 category memberships over 3 documents: the totals are allowed not to sum.
		$this->assertGreaterThan($f['completedDocuments'], array_sum($f['categories']));
		$this->assertSame(['PAY_AS_YOU_GO' => 1, 'PROMOTIONAL' => 1, 'UNKNOWN' => 1], $f['byEntitlementType']);
	}

	public function testFundingMixAggregatesAcrossRootChunks(): void {
		$roots = range(1, OperationsAnalyticsMapper::ID_CHUNK + 2);
		$this->mapper->method('completedRootIds')->willReturn($roots);
		$chunks = [];
		$this->mapper->method('signRequestsOfRoots')->willReturnCallback(function (array $ids) use (&$chunks): array {
			$chunks[] = count($ids);
			return array_map(static fn (int $id): array => [
				'rootId' => $id, 'signRequestId' => $id, 'sponsorshipType' => 'self',
				'metadata' => '{"entitlement_consumed":true,"entitlementId":11}',
			], $ids);
		});
		$this->mapper->method('entitlementTypes')->willReturn([11 => 'PAY_AS_YOU_GO']);

		$f = $this->service->entitlements($this->range)['metrics']['funding'];

		$this->assertSame([OperationsAnalyticsMapper::ID_CHUNK, 2], $chunks);
		$this->assertSame(count($roots), $f['completedDocuments']);
		$this->assertSame(count($roots), $f['categories']['selfEntitlement']);
		$this->assertSame(count($roots), $f['byEntitlementType']['PAY_AS_YOU_GO']);
	}

	public function testFundingMixIsEmptyNotFabricatedWithNoCompletedDocuments(): void {
		$f = $this->service->entitlements($this->range)['metrics']['funding'];

		$this->assertSame(0, $f['completedDocuments']);
		$this->assertSame(['requesterSponsored' => 0, 'selfEntitlement' => 0, 'directPay' => 0, 'unknown' => 0], $f['categories']);
		$this->assertSame([], $f['byEntitlementType']);
	}

	public function testNoIdentifiersLeakIntoResponses(): void {
		$this->stubPayments(['paid' => 1], ['paid' => 1], ['numerator' => 1, 'denominator' => 1]);
		$this->mapper->method('providerStatusCounts')->willReturn(['daraja' => ['paid' => 1]]);

		$json = json_encode([
			$this->service->overview($this->range),
			$this->service->payments($this->range),
			$this->service->funnel($this->range),
			$this->service->entitlements($this->range),
		]);

		foreach (['payment_attempt_id', 'provider_reference', 'phone', 'requester_email', 'id_number', 'user_id'] as $forbidden) {
			$this->assertStringNotContainsString($forbidden, $json);
		}
	}
}
