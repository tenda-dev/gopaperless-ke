<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Operations\Analytics\Mapper;

use OCA\Libresign\Db\Entitlement;
use OCA\Libresign\Db\EntitlementMapper;
use OCA\Libresign\Db\EntitlementReservation;
use OCA\Libresign\Db\EntitlementReservationMapper;
use OCA\Libresign\Db\Payment;
use OCA\Libresign\Db\File;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\PaymentMapper;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignerSponsorship;
use OCA\Libresign\Db\SignerSponsorshipMapper;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Enum\EntitlementType;
use OCA\Libresign\Enum\FileStatus;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\SignRequestStatus;
use OCA\Libresign\Service\Operations\Analytics\Mapper\OperationsAnalyticsMapper;
use OCA\Libresign\Service\Operations\Analytics\OperationsAnalyticsService;
use OCA\Libresign\Service\Operations\Analytics\ValueObject\DateRange;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\Server;

/**
 * Real-database coverage of the aggregate SQL.
 *
 * Rows are seeded on 2001-02-03 (Nairobi day) and queried with that single-day
 * range, so pre-existing data in the test database can't affect the assertions.
 * Entitlement balances aren't date-bounded, so they use a unique product code.
 *
 * @group DB
 */
final class OperationsAnalyticsMapperTest extends TestCase {
	private const DAY = '2001-02-03';
	private const PRODUCT = 'OPS_ANALYTICS_TEST';

	private OperationsAnalyticsMapper $mapper;
	private PaymentMapper $payments;
	private EntitlementMapper $entitlements;
	private FileMapper $files;
	private SignRequestMapper $signRequests;
	private SignerSponsorshipMapper $sponsorships;
	private EntitlementReservationMapper $reservations;
	private OperationsAnalyticsService $service;
	private DateRange $range;
	private int $seq = 0;

	public function setUp(): void {
		parent::setUp();
		$this->mapper = Server::get(OperationsAnalyticsMapper::class);
		$this->payments = Server::get(PaymentMapper::class);
		$this->entitlements = Server::get(EntitlementMapper::class);
		$this->files = Server::get(FileMapper::class);
		$this->signRequests = Server::get(SignRequestMapper::class);
		$this->sponsorships = Server::get(SignerSponsorshipMapper::class);
		$this->reservations = Server::get(EntitlementReservationMapper::class);
		$this->service = Server::get(OperationsAnalyticsService::class);
		$this->range = DateRange::fromRequest(self::DAY, self::DAY);
	}

	private function seedPayment(
		string $status,
		PaymentPurpose $purpose = PaymentPurpose::SIGN_REQUEST,
		?int $transactionId = null,
		string $createdAtUtc = '2001-02-03 09:00:00',
		string $provider = 'daraja',
		string $currency = 'KES',
		int $amount = 10000,
		?string $paidAtUtc = null,
	): void {
		$n = ++$this->seq;
		$p = new Payment();
		$p->setPaymentAttemptId('ops-test-' . uniqid('', true) . "-$n");
		$p->setProvider($provider);
		$p->setProductCode(self::PRODUCT);
		$p->setProductUses(1);
		$p->setQuantity(1);
		$p->setAmount($amount);
		$p->setUnitAmount($amount);
		$p->setCurrency($currency);
		$p->setStatus($status);
		$p->setPurpose($purpose->value);
		$p->setTransactionId($transactionId);
		$p->setProviderReference('ops-ref-' . uniqid('', true) . "-$n");
		$utc = new \DateTimeZone('UTC');
		$p->setCreatedAt(new \DateTime($createdAtUtc, $utc));
		$p->setUpdatedAt(new \DateTime($createdAtUtc, $utc));
		$p->setExpiresAt(new \DateTime('2001-02-04 00:00:00', $utc));
		if ($paidAtUtc !== null) {
			$p->setPaidAt(new \DateTime($paidAtUtc, $utc));
		}
		$this->payments->insert($p);
	}

	public function testStatusCountsAreSplitByPurposeAndIgnoreCertificatePurchases(): void {
		$this->seedPayment('paid');
		$this->seedPayment('failed');
		$this->seedPayment('pending');
		$this->seedPayment('paid', PaymentPurpose::CREDIT_PURCHASE);
		$this->seedPayment('paid', PaymentPurpose::CERTIFICATE_PURCHASE);

		$this->assertSame(
			['paid' => 1, 'failed' => 1, 'pending' => 1],
			$this->mapper->paymentStatusCounts(PaymentPurpose::SIGN_REQUEST, $this->range),
		);
		$this->assertSame(['paid' => 1], $this->mapper->paymentStatusCounts(PaymentPurpose::CREDIT_PURCHASE, $this->range));
		$providers = $this->mapper->providerStatusCounts($this->range);
		$this->assertSame(5 - 1, array_sum($providers['daraja'])); // certificate purchase excluded
	}

	public function testRangeIsHalfOpenOnEatDayBoundaries(): void {
		// EAT day 2001-02-03 = [02-02 21:00Z, 02-03 21:00Z)
		$this->seedPayment('paid', createdAtUtc: '2001-02-02 20:59:59');  // previous EAT day
		$this->seedPayment('paid', createdAtUtc: '2001-02-02 21:00:00');  // first instant: in
		$this->seedPayment('paid', createdAtUtc: '2001-02-03 20:59:59');  // last second: in
		$this->seedPayment('paid', createdAtUtc: '2001-02-03 21:00:00');  // next EAT day

		$this->assertSame(['paid' => 2], $this->mapper->paymentStatusCounts(PaymentPurpose::SIGN_REQUEST, $this->range));
	}

	public function testWorkflowSuccessCollapsesAttemptsPerTransaction(): void {
		// Workflow 9001: failed then paid. Workflow 9002: only failed. Workflow 9003: only pending (excluded).
		$this->seedPayment('failed', transactionId: 9001);
		$this->seedPayment('paid', transactionId: 9001);
		$this->seedPayment('failed', transactionId: 9002);
		$this->seedPayment('pending', transactionId: 9003);
		$this->seedPayment('initiation_failed', transactionId: 9003);

		$this->assertSame(['numerator' => 1, 'denominator' => 2], $this->mapper->signingWorkflowCounts($this->range));
	}

	public function testRevenueIsPerCurrencyFromPaidRowsByPaidAt(): void {
		$this->seedPayment('paid', currency: 'KES', amount: 10000, paidAtUtc: '2001-02-03 10:00:00');
		$this->seedPayment('paid', currency: 'KES', amount: 5000, paidAtUtc: '2001-02-03 11:00:00');
		$this->seedPayment('paid', currency: 'USD', amount: 700, paidAtUtc: '2001-02-03 12:00:00');
		$this->seedPayment('failed', currency: 'KES', amount: 99999, paidAtUtc: null);
		// Defunct certificate purchase, paid: must not affect v1 revenue.
		$this->seedPayment('paid', PaymentPurpose::CERTIFICATE_PURCHASE, currency: 'KES', amount: 88888, paidAtUtc: '2001-02-03 13:00:00');
		// Created in range but paid after it: belongs to the next day's revenue.
		$this->seedPayment('paid', currency: 'KES', amount: 123, paidAtUtc: '2001-02-04 10:00:00');

		$this->assertSame(
			[['currency' => 'KES', 'amountMinor' => 15000], ['currency' => 'USD', 'amountMinor' => 700]],
			$this->mapper->revenueByCurrency($this->range),
		);
	}

	public function testEntitlementBalancesExcludeExpiredAndFlagUnlimited(): void {
		$future = new \DateTime('+30 days', new \DateTimeZone('UTC'));
		$past = new \DateTime('-1 day', new \DateTimeZone('UTC'));
		$this->entitlements->insert($this->entitlement(10, 2, null));
		$this->entitlements->insert($this->entitlement(5, 0, $future));
		$this->entitlements->insert($this->entitlement(99, 0, $past));     // expired: excluded
		$this->entitlements->insert($this->entitlement(null, 0, null));    // unlimited: counted, not summed

		$rows = array_values(array_filter(
			$this->mapper->entitlementBalances(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))),
			static fn (array $r): bool => $r['productCode'] === self::PRODUCT,
		));

		$this->assertSame([['productCode' => self::PRODUCT, 'remaining' => 15, 'reserved' => 2, 'unlimited' => 1]], $rows);
	}

	public function testUnlimitedEntitlementReservationsDoNotDistortFiniteBalances(): void {
		$future = new \DateTime('+30 days', new \DateTimeZone('UTC'));
		$past = new \DateTime('-1 day', new \DateTimeZone('UTC'));
		$other = 'OPS_ANALYTICS_TEST_UNLIMITED_ONLY';

		// Product with finite and unlimited entitlements.
		$this->entitlements->insert($this->entitlement(10, 2, null));
		$this->entitlements->insert($this->entitlement(5, 1, $future));
		$this->entitlements->insert($this->entitlement(null, 7, null));     // unlimited with reservations: counted only
		$this->entitlements->insert($this->entitlement(99, 3, $past));      // expired: excluded
		$this->entitlements->insert($this->entitlement(null, 4, $past));    // expired unlimited: excluded
		// Product with only an unlimited entitlement that has reservations.
		$this->entitlements->insert($this->entitlement(null, 5, null, productCode: $other));

		$rows = [];
		foreach ($this->mapper->entitlementBalances(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) as $r) {
			$rows[$r['productCode']] = $r;
		}

		// Finite remaining and reserved cover the same rows, so available = 15 - 3 = 12.
		$this->assertSame(['productCode' => self::PRODUCT, 'remaining' => 15, 'reserved' => 3, 'unlimited' => 1], $rows[self::PRODUCT]);
		// An unlimited-only product reports zero finite credits and no negative available balance.
		$this->assertSame(['productCode' => $other, 'remaining' => 0, 'reserved' => 0, 'unlimited' => 1], $rows[$other]);
	}

	public function testGrantsByTypeCountsRowsCreatedInRange(): void {
		$this->entitlements->insert($this->entitlement(1, 0, null, EntitlementType::ADMIN_GRANTED, '2001-02-03 09:00:00'));
		$this->entitlements->insert($this->entitlement(1, 0, null, EntitlementType::ADMIN_GRANTED, '2001-02-03 10:00:00'));
		$this->entitlements->insert($this->entitlement(1, 0, null, EntitlementType::PROMOTIONAL, '2001-02-03 10:00:00'));
		$this->entitlements->insert($this->entitlement(1, 0, null, EntitlementType::PROMOTIONAL, '2001-02-05 10:00:00'));

		$this->assertSame(['ADMIN_GRANTED' => 2, 'PROMOTIONAL' => 1], $this->mapper->grantsByType($this->range));
	}

	private function seedFile(int $status, ?int $parentId = null, string $nodeType = 'file', string $createdAtUtc = '2001-02-03 09:00:00'): int {
		$f = new File();
		$f->setUserId('ops-analytics-test-user');
		$f->setUuid('ops-f-' . uniqid('', true) . '-' . (++$this->seq));
		$f->setName('ops-test-file');
		$f->setStatus($status);
		$f->setNodeType($nodeType);
		$f->setParentFileId($parentId);
		$f->setCreatedAt(new \DateTime($createdAtUtc, new \DateTimeZone('UTC')));
		return $this->files->insert($f)->getId();
	}

	/**
	 * @param array<string, mixed>|null $metadata
	 */
	private function seedSignRequest(int $fileId, ?string $signedAtUtc, ?array $metadata = null, string $sponsorshipType = 'self'): int {
		$sr = new SignRequest();
		$sr->setFileId($fileId);
		$sr->setUuid('ops-sr-' . uniqid('', true) . '-' . (++$this->seq));
		$sr->setDisplayName('ops signer');
		$sr->setCreatedAt(new \DateTime('2001-02-03 08:00:00', new \DateTimeZone('UTC')));
		$sr->setSponsorshipType($sponsorshipType);
		if ($metadata !== null) {
			$sr->setMetadata($metadata);
		}
		if ($signedAtUtc !== null) {
			$sr->setSigned(new \DateTime($signedAtUtc, new \DateTimeZone('UTC')));
			$sr->setStatus(SignRequestStatus::SIGNED->value);
		}
		return $this->signRequests->insert($sr)->getId();
	}

	public function testCompletedDocumentsCountEnvelopesViaChildrenAndStandaloneOnce(): void {
		$before = $this->mapper->completedDocumentTimes($this->range);

		// Standalone SIGNED document: its own signature at 10:00.
		$standalone = $this->seedFile(FileStatus::SIGNED->value);
		$this->seedSignRequest($standalone, '2001-02-03 10:00:00');

		// SIGNED envelope with two signed children (09:00, 11:00) and NO envelope-level
		// sign_request — the case the old root-only join dropped.
		$envelope = $this->seedFile(FileStatus::SIGNED->value, null, 'envelope');
		$this->seedSignRequest($this->seedFile(FileStatus::SIGNED->value, $envelope), '2001-02-03 09:00:00');
		$this->seedSignRequest($this->seedFile(FileStatus::SIGNED->value, $envelope), '2001-02-03 11:00:00');

		// Partially signed envelope: one child signed, one not — NOT completed.
		$partial = $this->seedFile(FileStatus::PARTIAL_SIGNED->value, null, 'envelope');
		$this->seedSignRequest($this->seedFile(FileStatus::SIGNED->value, $partial), '2001-02-03 09:30:00');
		$this->seedSignRequest($this->seedFile(FileStatus::ABLE_TO_SIGN->value, $partial), null);

		$times = $this->mapper->completedDocumentTimes($this->range);

		// Standalone once + envelope once; partial excluded. Children never counted separately.
		$this->assertCount(count($before) + 2, $times);

		$hours = array_map(static fn (string $t): string => (new \DateTime($t))->format('H:i:s'), $times);
		// Envelope completion is its LAST child signature (11:00), not the first (09:00).
		$this->assertContains('11:00:00', $hours);
		$this->assertNotContains('09:00:00', $hours);
	}

	// ── funding attribution (F1 / F2 / F3) ───────────────────

	private function seedEntitlement(EntitlementType $type): int {
		return $this->entitlements->insert($this->entitlement(10, 0, null, $type))->getId();
	}

	/** @return array<string, mixed> the settlement-written metadata shape */
	private function consumed(?int $entitlementId): array {
		$m = ['entitlement_consumed' => true, 'productCode' => self::PRODUCT];
		if ($entitlementId !== null) {
			$m['entitlementId'] = $entitlementId;
		}
		return $m;
	}

	/** One SIGNED standalone root with a single sign_request; returns [rootId, signRequestId]. */
	private function seedCompletedRoot(?array $metadata, string $sponsorshipType = 'self', string $signedAtUtc = '2001-02-03 10:00:00'): array {
		$root = $this->seedFile(FileStatus::SIGNED->value);
		return [$root, $this->seedSignRequest($root, $signedAtUtc, $metadata, $sponsorshipType)];
	}

	/** @return array<string, mixed> */
	private function metrics(): array {
		return $this->service->entitlements($this->range)['metrics'];
	}

	private function delta(array $after, array $before): array {
		$out = [];
		foreach ($after as $k => $v) {
			$d = $v - ($before[$k] ?? 0);
			if ($d !== 0) {
				$out[$k] = $d;
			}
		}
		return $out;
	}

	public function testFundingMixClassifiesEachEvidenceClass(): void {
		$payg = $this->seedEntitlement(EntitlementType::PAY_AS_YOU_GO);
		$promo = $this->seedEntitlement(EntitlementType::PROMOTIONAL);
		$before = $this->metrics()['funding'];

		// 1 requester-sponsored: marker + sponsorship_type=requester
		$this->seedCompletedRoot($this->consumed($payg), 'requester');
		// 2 self entitlement-funded: marker, sponsorship_type left at its default
		$this->seedCompletedRoot($this->consumed($promo));
		// 3 direct pay: no marker, paid payment keyed by the sign_request id; the retry must not double count
		[, $directSr] = $this->seedCompletedRoot(null);
		$this->seedPayment('failed', transactionId: $directSr);
		$this->seedPayment('paid', transactionId: $directSr);
		$this->seedPayment('paid', transactionId: $directSr);
		// 4 TRAP A: legacy sponsorship_type='self' default, no marker, no payment => unknown, NOT self-funded
		$this->seedCompletedRoot(null, 'self');
		// 5 requester intent but settlement never ran: no marker, no payment => unknown
		$this->seedCompletedRoot(null, 'requester');
		// 6 empty metadata object => unknown
		$this->seedCompletedRoot([]);
		// 7 marker without entitlementId (legacy writer) => self entitlement, pool UNKNOWN
		$this->seedCompletedRoot($this->consumed(null));
		// 8 a PENDING payment is not direct pay
		[, $pendingSr] = $this->seedCompletedRoot(null);
		$this->seedPayment('pending', transactionId: $pendingSr);

		$after = $this->metrics()['funding'];

		$this->assertSame(8, $after['completedDocuments'] - $before['completedDocuments']);
		$this->assertSame(
			['requesterSponsored' => 1, 'selfEntitlement' => 2, 'directPay' => 1, 'unknown' => 4],
			$this->delta($after['categories'], $before['categories']),
		);
		$this->assertSame(
			['PAY_AS_YOU_GO' => 1, 'PROMOTIONAL' => 1, 'UNKNOWN' => 1],
			$this->delta($after['byEntitlementType'], $before['byEntitlementType']),
		);
		$this->assertTrue($after['multiBucket']);
	}

	public function testMixedFundingRootIsCountedOncePerCategoryAndPool(): void {
		$payg = $this->seedEntitlement(EntitlementType::PAY_AS_YOU_GO);
		$promo = $this->seedEntitlement(EntitlementType::PROMOTIONAL);
		$before = $this->metrics()['funding'];

		// One envelope, three signers: two self-funded from the same pool, one requester-sponsored.
		$envelope = $this->seedFile(FileStatus::SIGNED->value, null, 'envelope');
		$this->seedSignRequest($this->seedFile(FileStatus::SIGNED->value, $envelope), '2001-02-03 09:00:00', $this->consumed($promo));
		$this->seedSignRequest($this->seedFile(FileStatus::SIGNED->value, $envelope), '2001-02-03 10:00:00', $this->consumed($promo));
		$this->seedSignRequest($this->seedFile(FileStatus::SIGNED->value, $envelope), '2001-02-03 11:00:00', $this->consumed($payg), 'requester');

		$after = $this->metrics()['funding'];

		$this->assertSame(1, $after['completedDocuments'] - $before['completedDocuments']);
		$this->assertSame(
			['requesterSponsored' => 1, 'selfEntitlement' => 1],
			$this->delta($after['categories'], $before['categories']),
		);
		// Two categories for one document: categories legitimately exceed completedDocuments.
		$this->assertSame(
			['PAY_AS_YOU_GO' => 1, 'PROMOTIONAL' => 1],
			$this->delta($after['byEntitlementType'], $before['byEntitlementType']),
		);
	}

	public function testReleasedReservationIsNotConsumption(): void {
		// Trap B: a reservation released by CANCELLATION carries released_at too.
		$payg = $this->seedEntitlement(EntitlementType::PAY_AS_YOU_GO);
		$before = $this->metrics();

		[$root, $sr] = $this->seedCompletedRoot(null, 'requester');
		$reservation = new EntitlementReservation();
		$reservation->setEntitlementId($payg);
		$reservation->setSponsorUserId('ops-analytics-test-user');
		$reservation->setFileId($root);
		$reservation->setSignRequestId($sr);
		$reservation->setQuantity(1);
		$reservation->setReleasedAt(new \DateTime('2001-02-03 10:30:00', new \DateTimeZone('UTC')));
		$this->reservations->insert($reservation);

		$after = $this->metrics();

		$this->assertSame(['unknown' => 1], $this->delta($after['funding']['categories'], $before['funding']['categories']));
		$this->assertSame([], $this->delta($after['funding']['byEntitlementType'], $before['funding']['byEntitlementType']));
		$this->assertSame($before['creditConsumption']['total'], $after['creditConsumption']['total']);
	}

	public function testCreditConsumptionByTypeIsAnchoredOnSignedAndIgnoresRootCompletion(): void {
		$payg = $this->seedEntitlement(EntitlementType::PAY_AS_YOU_GO);
		$admin = $this->seedEntitlement(EntitlementType::ADMIN_GRANTED);
		$before = $this->metrics()['creditConsumption'];

		$this->seedCompletedRoot($this->consumed($payg));
		$this->seedCompletedRoot($this->consumed($payg), 'requester');
		$this->seedCompletedRoot($this->consumed($admin));
		$this->seedCompletedRoot($this->consumed(null));                                            // legacy: pool unknown
		$this->seedCompletedRoot($this->consumed($payg), 'self', '2001-02-02 21:00:00');            // first instant of the EAT day: in
		$this->seedCompletedRoot($this->consumed($payg), 'self', '2001-02-02 20:59:59');            // previous EAT day: out
		$this->seedCompletedRoot($this->consumed($payg), 'self', '2001-02-03 21:00:00');            // next EAT day: out
		$this->seedCompletedRoot(null);                                                             // no marker: not consumption
		// Consumed on a document that is NOT completed (partial envelope): still a consumed credit.
		$partial = $this->seedFile(FileStatus::PARTIAL_SIGNED->value, null, 'envelope');
		$this->seedSignRequest($this->seedFile(FileStatus::SIGNED->value, $partial), '2001-02-03 12:00:00', $this->consumed($admin));

		$after = $this->metrics()['creditConsumption'];

		$this->assertSame(6, $after['total'] - $before['total']);
		$this->assertSame(
			['ADMIN_GRANTED' => 2, 'PAY_AS_YOU_GO' => 3, 'UNKNOWN' => 1],
			$this->delta($after['byType'], $before['byType']),
		);
		$this->assertSame('signed', $after['timeField']);
	}

	public function testRequesterSponsorshipActivityCountsCreationNotConsumption(): void {
		$before = $this->metrics();

		$envelope = $this->seedFile(FileStatus::ABLE_TO_SIGN->value, null, 'envelope');
		$childA = $this->seedFile(FileStatus::ABLE_TO_SIGN->value, $envelope);
		$childB = $this->seedFile(FileStatus::ABLE_TO_SIGN->value, $envelope);
		$standalone = $this->seedFile(FileStatus::ABLE_TO_SIGN->value);
		$this->seedSponsorship($childA, 'requester', '2001-02-03 09:00:00');
		$this->seedSponsorship($childB, 'requester', '2001-02-03 09:00:00');   // same root as childA
		$this->seedSponsorship($standalone, 'requester', '2001-02-03 09:00:00');
		$this->seedSponsorship($standalone, 'self', '2001-02-03 09:00:00');    // not requester
		$this->seedSponsorship($standalone, 'requester', '2001-02-04 09:00:00'); // outside the range

		$after = $this->metrics();

		$this->assertSame(3, $after['sponsorshipMix']['requesterActivity']['records'] - $before['sponsorshipMix']['requesterActivity']['records']);
		$this->assertSame(2, $after['sponsorshipMix']['requesterActivity']['documents'] - $before['sponsorshipMix']['requesterActivity']['documents']);
		$this->assertSame('sponsorships_created', $after['sponsorshipMix']['requesterActivity']['label']);
		// Intent only: nothing was completed or consumed.
		$this->assertSame($before['creditConsumption']['total'], $after['creditConsumption']['total']);
		$this->assertSame($before['funding']['completedDocuments'], $after['funding']['completedDocuments']);
		// The existing per-type mix keeps its shape.
		$this->assertSame(3, ($after['sponsorshipMix']['byType']['requester'] ?? 0) - ($before['sponsorshipMix']['byType']['requester'] ?? 0));
	}

	public function testCompletedRootIdsAreExactlyTheDocumentsCompletedDocumentTimesCounts(): void {
		$beforeIds = count($this->mapper->completedRootIds($this->range));
		$beforeTimes = count($this->mapper->completedDocumentTimes($this->range));

		$this->seedCompletedRoot(null);
		$envelope = $this->seedFile(FileStatus::SIGNED->value, null, 'envelope');
		$this->seedSignRequest($this->seedFile(FileStatus::SIGNED->value, $envelope), '2001-02-03 09:00:00');
		$this->seedSignRequest($this->seedFile(FileStatus::SIGNED->value, $envelope), '2001-02-03 11:00:00');
		$partial = $this->seedFile(FileStatus::PARTIAL_SIGNED->value, null, 'envelope');
		$this->seedSignRequest($this->seedFile(FileStatus::SIGNED->value, $partial), '2001-02-03 09:30:00');

		$ids = $this->mapper->completedRootIds($this->range);
		$this->assertSame(2, count($ids) - $beforeIds);
		$this->assertSame(count($ids), count($this->mapper->completedDocumentTimes($this->range)));
		$this->assertSame(2, count($this->mapper->completedDocumentTimes($this->range)) - $beforeTimes);
	}

	public function testFundingLookupsAreChunkSafeBeyondOneInList(): void {
		$payg = $this->seedEntitlement(EntitlementType::PAY_AS_YOU_GO);
		$before = $this->metrics()['funding'];
		$n = OperationsAnalyticsMapper::ID_CHUNK + 3; // forces >1 root chunk in the service

		for ($i = 0; $i < $n; $i++) {
			$this->seedCompletedRoot($this->consumed($payg));
		}

		$after = $this->metrics()['funding'];
		$this->assertSame($n, $after['completedDocuments'] - $before['completedDocuments']);
		$this->assertSame($n, $after['categories']['selfEntitlement'] - $before['categories']['selfEntitlement']);
		$this->assertSame($n, ($after['byEntitlementType']['PAY_AS_YOU_GO'] ?? 0) - ($before['byEntitlementType']['PAY_AS_YOU_GO'] ?? 0));
	}

	private function seedSponsorship(int $fileId, string $type, string $createdAtUtc): void {
		$s = new SignerSponsorship();
		$s->setFileId($fileId);
		$s->setSignRequestId($this->seedSignRequest($fileId, null));
		$s->setSponsorUserId('ops-analytics-test-user');
		$s->setSponsorshipType($type);
		$s->setCreatedAt(new \DateTime($createdAtUtc, new \DateTimeZone('UTC')));
		$this->sponsorships->insert($s);
	}

	private function entitlement(
		?int $remaining,
		int $reserved,
		?\DateTime $expiresAt,
		EntitlementType $type = EntitlementType::PAY_AS_YOU_GO,
		string $createdAtUtc = '2001-01-01 00:00:00',
		string $productCode = self::PRODUCT,
	): Entitlement {
		$e = new Entitlement();
		$e->setUserId('ops-analytics-test-user');
		$e->setProductCode($productCode);
		$e->setRemainingUses($remaining);
		$e->setReservedUses($reserved);
		$e->setExpiresAt($expiresAt);
		$e->setEntitlementType($type);
		$e->setCreatedAt(new \DateTime($createdAtUtc, new \DateTimeZone('UTC')));
		return $e;
	}
}
