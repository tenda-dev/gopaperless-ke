<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Operations\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Service\Operations\Analytics\DTO\Metric;
use OCA\Libresign\Service\Operations\Analytics\Exception\InvalidRangeException;
use OCA\Libresign\Service\Operations\Analytics\Mapper\OperationsAnalyticsMapper;
use OCA\Libresign\Service\Operations\Analytics\ValueObject\DateRange;

/**
 * Read model facade for the Operations dashboard.
 *
 * Combines mapper aggregates into the API response shapes. Responses contain
 * aggregate data only; identifiers and personal or payment-reference data
 * are not exposed.
 */
class OperationsAnalyticsService {
	public const SERIES_SIGNING = 'signing';
	public const SERIES_PAYMENTS = 'payments';
	/** Allowed timeseries groups; registrations are unavailable without a creation timestamp. */
	public const SERIES_ALLOWED = [self::SERIES_SIGNING, self::SERIES_PAYMENTS];

	private const STATUS_DRAFT = 0;
	private const STATUS_ABLE_TO_SIGN = 1;
	private const STATUS_PARTIAL_SIGNED = 2;
	private const STATUS_SIGNED = 3;
	private const STATUS_DELETED = 4;
	private const STATUS_SIGNING_IN_PROGRESS = 5;

	public function __construct(
		private OperationsAnalyticsMapper $mapper,
		private AccountCountsProvider $accountCounts,
	) {
	}

	/**
	 * @throws InvalidRangeException
	 */
	public function range(string $from, string $to, string $tz): DateRange {
		return DateRange::fromRequest($from, $to, $tz);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function overview(DateRange $range): array {
		$accounts = $this->accountCounts->counts();
		$account = $this->mapper->accountIdentifierKey();
		$signers = $this->mapper->distinctSigners($range, $account);
		$emailSigners = $this->mapper->distinctSigners($range, 'email');

		return [
			'meta' => $this->meta($range),
			'metrics' => [
				'registeredAccountsLifetime' => $accounts['lifetime'] === null
					? Metric::unavailable('count_unavailable')
					: Metric::value($accounts['lifetime'], Metric::GRAIN_POINT_IN_TIME),
				'enabledAccounts' => $accounts['enabled'] === null
					? Metric::unavailable('count_unavailable')
					: Metric::value($accounts['enabled'], Metric::GRAIN_POINT_IN_TIME),
				'newRegistrations' => Metric::unavailable('no_registration_timestamp'),
				'usersWhoSigned' => Metric::value($signers, Metric::GRAIN_PERIOD),
				// This sum can count one person twice if they sign through both an account
				// and an email. Mark it as an unconfirmed upper bound, not an exact person count.
				'distinctSignersInclEmail' => Metric::value($signers + $emailSigners, Metric::GRAIN_PERIOD, false) + ['upperBound' => true],
				'signaturesCollected' => Metric::value(count($this->mapper->signatureTimes($range)), Metric::GRAIN_PERIOD),
				'documentsCreated' => Metric::value(count($this->mapper->documentCreatedTimes($range)), Metric::GRAIN_PERIOD),
				'completedDocuments' => Metric::value(count($this->mapper->completedDocumentTimes($range)), Metric::GRAIN_PERIOD),
			] + $this->paymentMetrics($range) + [
				// Keep F2 consistent with the entitlements endpoint for the same date range.
				'creditConsumption' => $this->creditConsumption($range),
			],
		];
	}

	/**
	 * @param list<string> $metrics subset of SERIES_ALLOWED; empty = all
	 * @return array<string, mixed>
	 * @throws InvalidRangeException on an unsupported metric key
	 */
	public function timeseries(DateRange $range, array $metrics): array {
		$metrics = $metrics === [] ? self::SERIES_ALLOWED : array_values(array_unique($metrics));
		foreach ($metrics as $metric) {
			if (!in_array($metric, self::SERIES_ALLOWED, true)) {
				throw new InvalidRangeException(InvalidRangeException::INVALID_PARAMS);
			}
		}

		// Series use different date anchors: signatures by signed time, document
		// creation by created_at, and completed documents by completion time.
		$series = [];
		if (in_array(self::SERIES_SIGNING, $metrics, true)) {
			$series['signatures'] = $this->daily($range, $this->mapper->signatureTimes($range));
			$series['documentsCreated'] = $this->daily($range, $this->mapper->documentCreatedTimes($range));
			$series['completedDocuments'] = $this->daily($range, $this->mapper->completedDocumentTimes($range));
		}
		if (in_array(self::SERIES_PAYMENTS, $metrics, true)) {
			$attempts = $this->mapper->paymentAttemptTimes($range);
			$series['paymentAttempts'] = $this->daily($range, array_column($attempts, 'at'));
			// Bucket paid attempts by created_at to keep them comparable with all
			// attempts. Revenue uses paid_at because it measures confirmed payments.
			$series['paymentsPaid'] = $this->daily($range, array_column(
				array_filter($attempts, static fn (array $a): bool => $a['status'] === PaymentStatus::PAID->value),
				'at',
			));
		}
		// Core accounts have no reliable creation timestamp, so registrations are
		// unavailable rather than reported as zero.
		$series['newRegistrations'] = Metric::unavailable('no_registration_timestamp');

		return ['meta' => $this->meta($range), 'series' => $series];
	}

	/**
	 * Created-document cohort by lifecycle stage, plus public-upload activity.
	 *
	 * @return array<string, mixed>
	 */
	public function funnel(DateRange $range): array {
		$byStatus = $this->mapper->createdDocumentsByStatus($range);
		$n = static fn (int ...$statuses): int => array_sum(array_map(static fn (int $s): int => $byStatus[$s] ?? 0, $statuses));

		// These stages use different units and date anchors: documents created,
		// individual signatures, and completed documents. They are not a conversion
		// sequence; unitsDiffer prevents the UI from presenting conversion rates.
		$initiated = $n(self::STATUS_ABLE_TO_SIGN, self::STATUS_PARTIAL_SIGNED, self::STATUS_SIGNED, self::STATUS_SIGNING_IN_PROGRESS);
		$signaturesCompleted = count($this->mapper->signatureTimes($range));
		$completed = count($this->mapper->completedDocumentTimes($range));

		return [
			'meta' => $this->meta($range),
			'unitsDiffer' => true,
			'stages' => [
				['key' => 'workflowsInitiated', 'unit' => 'documents', 'bucket' => 'created_at', 'count' => $initiated],
				['key' => 'signaturesCompleted', 'unit' => 'signatures', 'bucket' => 'signed', 'count' => $signaturesCompleted],
				['key' => 'fullyCompletedDocuments', 'unit' => 'documents', 'bucket' => 'completion', 'count' => $completed],
			],
			'publicUpload' => ['byStatus' => $this->mapper->publicWorkflowsByStatus($range)],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function payments(DateRange $range): array {
		$providers = [];
		foreach ($this->mapper->providerStatusCounts($range) as $provider => $byStatus) {
			$providers[] = ['provider' => $provider, 'byStatus' => $byStatus, 'attempts' => array_sum($byStatus)];
		}
		usort($providers, static fn (array $a, array $b): int => strcmp($a['provider'], $b['provider']));

		return [
			'meta' => $this->meta($range),
			'metrics' => $this->paymentMetrics($range),
			'outcomes' => [
				'signRequest' => $this->mapper->paymentStatusCounts(PaymentPurpose::SIGN_REQUEST, $range),
				'creditPurchase' => $this->mapper->paymentStatusCounts(PaymentPurpose::CREDIT_PURCHASE, $range),
			],
			'providers' => $providers,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function entitlements(DateRange $range): array {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$balances = $this->mapper->entitlementBalances($now);

		$remaining = array_sum(array_column($balances, 'remaining'));
		$reserved = array_sum(array_column($balances, 'reserved'));
		$unlimited = array_sum(array_column($balances, 'unlimited'));

		return [
			'meta' => $this->meta($range),
			'metrics' => [
				// Available credits = remaining - reserved, matching Entitlement::getAvailableUses().
				// Reservations hold capacity without reducing remaining_uses; credits are
				// consumed only at settlement. Unlimited entitlements are excluded from
				// remaining, reserved and available (including any reservations recorded
				// against them) and reported only in unlimitedEntitlements.
				'availableCredits' => [
					'grain' => Metric::GRAIN_POINT_IN_TIME,
					'remaining' => $remaining,
					'reserved' => $reserved,
					'available' => $remaining - $reserved,
					'unlimitedEntitlements' => $unlimited,
					'byProduct' => $balances,
				],
				'creditGrants' => ['grain' => Metric::GRAIN_PERIOD, 'byType' => $this->mapper->grantsByType($range)],
				// Sponsorship records measure draft-time intent, not completed signing or
				// credit consumption. Consumption is reported separately.
				'sponsorshipMix' => [
					'grain' => Metric::GRAIN_PERIOD,
					'byType' => $this->mapper->sponsorshipMix($range),
					'requesterActivity' => $this->requesterActivity($range),
				],
				'creditConsumption' => $this->creditConsumption($range),
				'funding' => $this->fundingMix($range),
			],
		];
	}

	/**
	 * Returns requester sponsorship records created within the selected period.
	 *
	 * @return array{label: string, records: int, documents: int}
	 */
	private function requesterActivity(DateRange $range): array {
		$activity = $this->mapper->requesterSponsorshipActivity($range);
		return [
			'label' => 'sponsorships_created',
			'records' => $activity['records'] ?? 0,
			'documents' => $activity['documents'] ?? 0,
		];
	}

	/**
	 * Credits consumed by entitlement type, dated by sign_request.signed.
	 *
	 * There is no consumed_at timestamp, so signed is the reporting proxy. Each
	 * settled signing counts as one credit, including unlimited entitlements.
	 * Reservations and sponsorship records do not count as consumption.
	 *
	 * This metric counts credits; fundingMix() counts documents by entitlement
	 * type, so their type breakdowns have different units.
	 *
	 * @return array<string, mixed>
	 */
	private function creditConsumption(DateRange $range): array {
		$byEntitlement = $this->mapper->consumedByEntitlement($range);
		$types = $this->mapper->entitlementTypes(array_values(array_filter(
			array_keys($byEntitlement),
			static fn (int $id): bool => $id > 0,
		)));

		$byType = [];
		$total = 0;
		foreach ($byEntitlement as $entitlementId => $count) {
			$pool = $types[$entitlementId] ?? FundingClassifier::UNKNOWN_POOL;
			$byType[$pool] = ($byType[$pool] ?? 0) + $count;
			$total += $count;
		}
		ksort($byType);

		return [
			'grain' => Metric::GRAIN_PERIOD,
			'unit' => 'credits',
			'timeField' => 'signed',
			'basis' => 'one_credit_per_settled_signing',
			'total' => $total,
			'byType' => $byType,
		];
	}

	/**
	 * Funding mix for the completed documents in the selected period.
	 *
	 * Counts root documents once per funding category. A document may appear in
	 * multiple categories when its signers use different funding methods, so
	 * category totals may exceed the completed-document count.
	 *
	 * Processes root IDs in disjoint chunks, loading sign requests, payment
	 * evidence for unsettled signings, and entitlement types in batches.
	 *
	 * @return array<string, mixed>
	 */
	private function fundingMix(DateRange $range): array {
		$roots = $this->mapper->completedRootIds($range);
		$categories = array_fill_keys(FundingClassifier::CATEGORIES, 0);
		$byPool = [];
		$typeCache = [];

		foreach (array_chunk($roots, OperationsAnalyticsMapper::ID_CHUNK) as $chunk) {
			$rows = $this->mapper->signRequestsOfRoots($chunk);

			$unsettled = [];
			$poolIds = [];
			foreach ($rows as $i => $row) {
				$settlement = FundingClassifier::settlement($row['metadata']);
				$rows[$i]['settlement'] = $settlement;
				if (!$settlement['consumed']) {
					$unsettled[] = $row['signRequestId'];
				} elseif ($settlement['entitlementId'] !== null) {
					$poolIds[$settlement['entitlementId']] = true;
				}
			}

			// Only check payments for signings without a settlement marker. Successful
			// signer payments create PAY_AS_YOU_GO credits, which are consumed at
			// settlement; settled signings are classified by the consumed entitlement.
			$paid = $this->mapper->signRequestsWithPaidPayment($unsettled);
			$missing = array_values(array_diff(array_keys($poolIds), array_keys($typeCache)));
			$typeCache += $this->mapper->entitlementTypes($missing);

			$rootCategories = [];
			$rootPools = [];
			foreach ($rows as $row) {
				$settlement = $row['settlement'];
				$category = FundingClassifier::classify(
					$settlement['consumed'],
					$row['sponsorshipType'],
					isset($paid[$row['signRequestId']]),
				);
				$rootCategories[$row['rootId']][$category] = true;
				if ($settlement['consumed']) {
					$pool = $settlement['entitlementId'] !== null
						? ($typeCache[$settlement['entitlementId']] ?? FundingClassifier::UNKNOWN_POOL)
						: FundingClassifier::UNKNOWN_POOL;
					$rootPools[$row['rootId']][$pool] = true;
				}
			}

			foreach ($rootCategories as $present) {
				foreach (array_keys($present) as $category) {
					$categories[$category]++;
				}
			}
			foreach ($rootPools as $present) {
				foreach (array_keys($present) as $pool) {
					$byPool[$pool] = ($byPool[$pool] ?? 0) + 1;
				}
			}
		}
		ksort($byPool);

		return [
			'grain' => Metric::GRAIN_PERIOD,
			'unit' => 'documents',
			'dateField' => 'completion',
			'multiBucket' => true,
			'completedDocuments' => count($roots),
			'categories' => $categories,
			'byEntitlementType' => $byPool,
		];
	}

	/**
	 * Builds the separate payment-success rates and revenue metric.
	 *
	 * Each rate has its own denominator:
	 * - signingPaymentSuccess: terminal signing-payment attempts; retries count.
	 * - signingWorkflowSuccess: distinct sign_request IDs (one per signer, not
	 *   one per document).
	 * - creditPurchaseSuccess: terminal credit-purchase attempts.
	 *
	 * Rates use attempt created_at; revenue uses paid_at.
	 *
	 * @return array<string, mixed>
	 */
	private function paymentMetrics(DateRange $range): array {
		$sign = $this->attemptRate($this->mapper->paymentStatusCounts(PaymentPurpose::SIGN_REQUEST, $range));
		$credit = $this->attemptRate($this->mapper->paymentStatusCounts(PaymentPurpose::CREDIT_PURCHASE, $range));
		$workflow = $this->mapper->signingWorkflowCounts($range);

		return [
			'signingPaymentSuccess' => Metric::rate($sign[0], $sign[1], 'payment_attempt'),
			'signingWorkflowSuccess' => Metric::rate($workflow['numerator'], $workflow['denominator'], 'workflow'),
			'creditPurchaseSuccess' => Metric::rate($credit[0], $credit[1], 'payment_attempt'),
			'revenue' => [
				'grain' => Metric::GRAIN_PERIOD,
				'timeField' => 'paid_at',
				'byCurrency' => $this->mapper->revenueByCurrency($range),
			],
		];
	}

	/**
	 * Returns paid attempts divided by terminal payment outcomes.
	 *
	 * Pending attempts are excluded because verification may still change their
	 * status. Initiation failures are excluded because the provider never accepted
	 * the attempt; they do not establish a payment outcome.
	 *
	 * @param array<string, int> $byStatus
	 * @return array{0: int, 1: int} [numerator, denominator]
	 */
	private function attemptRate(array $byStatus): array {
		$paid = $byStatus[PaymentStatus::PAID->value] ?? 0;
		$terminal = $paid
			+ ($byStatus[PaymentStatus::FAILED->value] ?? 0)
			+ ($byStatus[PaymentStatus::EXPIRED->value] ?? 0)
			+ ($byStatus[PaymentStatus::CANCELLED->value] ?? 0);
		return [$paid, $terminal];
	}

	/**
	 * Zero-filled daily counts in the range's timezone.
	 *
	 * @param list<string> $utcTimestamps
	 * @return list<array{date: string, count: int}>
	 */
	private function daily(DateRange $range, array $utcTimestamps): array {
		$buckets = array_fill_keys($range->days(), 0);
		foreach ($utcTimestamps as $ts) {
			$day = $range->bucketOf($ts);
			if (isset($buckets[$day])) {
				$buckets[$day]++;
			}
		}
		$out = [];
		foreach ($buckets as $date => $count) {
			$out[] = ['date' => (string)$date, 'count' => $count];
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function meta(DateRange $range): array {
		return [
			'from' => $range->from,
			'to' => $range->to,
			'tz' => $range->tzName(),
			'inclusive' => 'both',
			'generatedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
		];
	}
}
