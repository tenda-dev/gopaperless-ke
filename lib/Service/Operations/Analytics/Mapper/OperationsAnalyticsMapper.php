<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Operations\Analytics\Mapper;

use OCA\Libresign\Enum\NodeType;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Enum\SponsorshipType;
use OCA\Libresign\Service\Operations\Analytics\FundingClassifier;
use OCA\Libresign\Service\IdentifyMethodService;
use OCA\Libresign\Service\Operations\Analytics\ValueObject\DateRange;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Read-only aggregate queries for Operations Analytics.
 *
 * Counts, sums and grouped results are calculated in the database. The
 * Times methods return timestamps for PHP-side calendar-day bucketing,
 * which keeps date grouping consistent across supported database engines.
 *
 * All date filters use the half-open UTC interval
 * [startUtc, endUtcExclusive).
 */
class OperationsAnalyticsMapper {
	/** Payment purposes included in analytics; CERTIFICATE_PURCHASE is defunct and excluded. */
	private const PURPOSES = [PaymentPurpose::SIGN_REQUEST, PaymentPurpose::CREDIT_PURCHASE];

	/** Terminal outcomes used in success-rate denominators. (PENDING and INITIATION_FAILED are excluded). */
	private const TERMINAL = [
		PaymentStatus::PAID,
		PaymentStatus::FAILED,
		PaymentStatus::EXPIRED,
		PaymentStatus::CANCELLED,
	];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	// ── signing ──────────────────────────────────────────────

	/**
	 * Signatures collected: signed sign_requests on standalone files / envelope
	 * children. Envelope-level rows are excluded so a signature isn't counted
	 * once on the envelope and again on each child.
	 *
	 * @return list<string> UTC 'Y-m-d H:i:s' signed timestamps
	 */
	public function signatureTimes(DateRange $range): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('sr.signed')
			->from('libresign_sign_request', 'sr')
			->innerJoin('sr', 'libresign_file', 'f', $qb->expr()->eq('f.id', 'sr.file_id'))
			->where($this->inRange($qb, 'sr.signed', $range))
			->andWhere($qb->expr()->neq('f.node_type', $qb->createNamedParameter(NodeType::ENVELOPE->value)));
		return $this->column($qb, 'signed');
	}

	/**
	 * Distinct signers by identify-method key (`account` or `email`).
	 */
	public function distinctSigners(DateRange $range, string $identifierKey): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(DISTINCT im.identifier_value)'), 'c')
			->from('libresign_identify_method', 'im')
			->innerJoin('im', 'libresign_sign_request', 'sr', $qb->expr()->eq('sr.id', 'im.sign_request_id'))
			->where($qb->expr()->eq('im.identifier_key', $qb->createNamedParameter($identifierKey)))
			->andWhere($this->inRange($qb, 'sr.signed', $range));
		return $this->scalar($qb);
	}

	public function accountIdentifierKey(): string {
		return IdentifyMethodService::IDENTIFY_ACCOUNT;
	}

	/**
	 * Documents created (envelopes + standalone; children excluded).
	 *
	 * @return list<string> UTC created_at timestamps
	 */
	public function documentCreatedTimes(DateRange $range): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.created_at')
			->from('libresign_file', 'f')
			->where($qb->expr()->isNull('f.parent_file_id'))
			->andWhere($this->inRange($qb, 'f.created_at', $range));
		return $this->column($qb, 'created_at');
	}

	/**
	 * Created-document cohort by status (funnel). Children excluded.
	 *
	 * @return array<int, int> file status => count
	 */
	public function createdDocumentsByStatus(DateRange $range): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.status')
			->selectAlias($qb->func()->count('*'), 'c')
			->from('libresign_file', 'f')
			->where($qb->expr()->isNull('f.parent_file_id'))
			->andWhere($this->inRange($qb, 'f.created_at', $range))
			->groupBy('f.status');
		$out = [];
		foreach ($this->rows($qb) as $row) {
			$out[(int)$row['status']] = (int)$row['c'];
		}
		return $out;
	}

	/**
	 * Completed documents: SIGNED root files whose completion moment
	 * (MAX of their sign_request.signed) falls in the range. SignFileService
	 * only sets SIGNED once every signer has signed.
	 *
	 * @return list<string> UTC completion timestamps, one per document
	 *
	 * completedRootIds() repeats this predicate to obtain ids instead of times; the
	 * two must stay in lockstep (OperationsAnalyticsMapperTest asserts they agree).
	 */
	public function completedDocumentTimes(DateRange $range): array {
		$qb = $this->db->getQueryBuilder();
		$completedAt = $qb->createFunction('MAX(sr.signed)');
		// Signatures may belong to the root file or its children. For envelopes,
		// joining both lets MAX(sr.signed) identify the final signature across
		// the whole document, even when no envelope-level sign_request exists.
		// Grouping by root ensures each completed document is counted once.
		$qb->selectAlias($completedAt, 'completed_at')
			->from('libresign_file', 'r')
			->innerJoin('r', 'libresign_file', 'df', $qb->expr()->orX(
				$qb->expr()->eq('df.id', 'r.id'),
				$qb->expr()->eq('df.parent_file_id', 'r.id'),
			))
			->innerJoin('df', 'libresign_sign_request', 'sr', $qb->expr()->eq('sr.file_id', 'df.id'))
			->where($qb->expr()->isNull('r.parent_file_id'))
			->andWhere($qb->expr()->eq('r.status', $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT)))
			->groupBy('r.id')
			->having($qb->expr()->gte($completedAt, $qb->createNamedParameter($range->startUtc, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)))
			->andHaving($qb->expr()->lt($completedAt, $qb->createNamedParameter($range->endUtcExclusive, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)));
		return $this->column($qb, 'completed_at');
	}

	// ── payments ─────────────────────────────────────────────

	/**
	 * Attempt counts by status for one purpose, bucketed by created_at.
	 *
	 * @return array<string, int> status => count
	 */
	public function paymentStatusCounts(PaymentPurpose $purpose, DateRange $range): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('p.status')
			->selectAlias($qb->func()->count('*'), 'c')
			->from('gopaperless_payments', 'p')
			->where($qb->expr()->eq('p.purpose', $qb->createNamedParameter($purpose->value)))
			->andWhere($this->inRange($qb, 'p.created_at', $range))
			->groupBy('p.status');
		$out = [];
		foreach ($this->rows($qb) as $row) {
			$out[(string)$row['status']] = (int)$row['c'];
		}
		return $out;
	}

	/**
	 * Signing workflow success counts (distinct transaction_id).
	 *
	 * transaction_id is the sign_request id, so the unit is one signer's signing:
	 * retries for the same sign_request collapse, but two signers of one document
	 * count twice.
	 *
	 * @return array{numerator: int, denominator: int}
	 */
	public function signingWorkflowCounts(DateRange $range): array {
		return [
			'numerator' => $this->distinctWorkflows($range, [PaymentStatus::PAID]),
			'denominator' => $this->distinctWorkflows($range, self::TERMINAL),
		];
	}

	/**
	 * @param list<PaymentStatus> $statuses
	 */
	private function distinctWorkflows(DateRange $range, array $statuses): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(DISTINCT p.transaction_id)'), 'c')
			->from('gopaperless_payments', 'p')
			->where($qb->expr()->eq('p.purpose', $qb->createNamedParameter(PaymentPurpose::SIGN_REQUEST->value)))
			->andWhere($qb->expr()->isNotNull('p.transaction_id'))
			->andWhere($qb->expr()->in('p.status', $qb->createNamedParameter(
				array_map(static fn (PaymentStatus $s): string => $s->value, $statuses),
				IQueryBuilder::PARAM_STR_ARRAY,
			)))
			->andWhere($this->inRange($qb, 'p.created_at', $range));
		return $this->scalar($qb);
	}

	/**
	 * Attempts per provider and status (sign_request + credit_purchase).
	 *
	 * @return array<string, array<string, int>> provider => status => count
	 */
	public function providerStatusCounts(DateRange $range): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('p.provider', 'p.status')
			->selectAlias($qb->func()->count('*'), 'c')
			->from('gopaperless_payments', 'p')
			->where($this->purposeIn($qb))
			->andWhere($this->inRange($qb, 'p.created_at', $range))
			->groupBy('p.provider', 'p.status');
		$out = [];
		foreach ($this->rows($qb) as $row) {
			$out[(string)$row['provider']][(string)$row['status']] = (int)$row['c'];
		}
		return $out;
	}

	/**
	 * Confirmed revenue per currency, minor units, from paid rows by paid_at. No FX.
	 *
	 * @return list<array{currency: string, amountMinor: int}>
	 */
	public function revenueByCurrency(DateRange $range): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('p.currency')
			->selectAlias($qb->func()->sum('p.amount'), 's')
			->from('gopaperless_payments', 'p')
			->where($qb->expr()->eq('p.status', $qb->createNamedParameter(PaymentStatus::PAID->value)))
			->andWhere($this->purposeIn($qb))
			->andWhere($this->inRange($qb, 'p.paid_at', $range))
			->groupBy('p.currency')
			->orderBy('p.currency');
		return array_map(
			static fn (array $r): array => ['currency' => (string)$r['currency'], 'amountMinor' => (int)$r['s']],
			$this->rows($qb),
		);
	}

	/**
	 * Attempt rows for the daily payments series (created_at + status only).
	 *
	 * @return list<array{at: string, status: string}>
	 */
	public function paymentAttemptTimes(DateRange $range): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('p.created_at', 'p.status')
			->from('gopaperless_payments', 'p')
			->where($this->purposeIn($qb))
			->andWhere($this->inRange($qb, 'p.created_at', $range));
		return array_map(
			static fn (array $r): array => ['at' => (string)$r['created_at'], 'status' => (string)$r['status']],
			$this->rows($qb),
		);
	}

	// ── entitlements / sponsorship / public upload ───────────

	/**
	 * Point-in-time balances of non-expired entitlements, per product.
	 * remaining_uses NULL = unlimited. Unlimited rows are only counted in `unlimited`;
	 * they are left out of both `remaining` and `reserved`, so the finite figures stay
	 * consistent. Reservations can still be recorded against an unlimited entitlement,
	 * but they hold no finite capacity.
	 * reserved_uses on finite rows is capacity held for in-flight sponsored workflows; a
	 * reservation leaves remaining_uses untouched, so available = remaining - reserved.
	 *
	 * @return list<array{productCode: string, remaining: int, reserved: int, unlimited: int}>
	 */
	public function entitlementBalances(\DateTimeImmutable $nowUtc): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('e.product_code')
			->selectAlias($qb->func()->sum('e.remaining_uses'), 'remaining')
			->selectAlias($qb->createFunction('SUM(CASE WHEN e.remaining_uses IS NOT NULL THEN e.reserved_uses ELSE 0 END)'), 'reserved')
			->selectAlias($qb->createFunction('SUM(CASE WHEN e.remaining_uses IS NULL THEN 1 ELSE 0 END)'), 'unlimited')
			->from('gopaperless_entitlements', 'e')
			->where($qb->expr()->orX(
				$qb->expr()->isNull('e.expires_at'),
				$qb->expr()->gt('e.expires_at', $qb->createNamedParameter($nowUtc, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)),
			))
			->groupBy('e.product_code')
			->orderBy('e.product_code');
		return array_map(static fn (array $r): array => [
			'productCode' => (string)$r['product_code'],
			'remaining' => (int)$r['remaining'],
			'reserved' => (int)$r['reserved'],
			'unlimited' => (int)$r['unlimited'],
		], $this->rows($qb));
	}

	/**
	 * Counts entitlement records created in the period, by type.
	 *
	 * This counts grants, not purchases. Finalised signing payments and credit
	 * purchases can both create PAY_AS_YOU_GO entitlements.
	 *
	 * @return array<string, int> type => count
	 */
	public function grantsByType(DateRange $range): array {
		return $this->countByColumn('gopaperless_entitlements', 'e', 'type', 'created_at', $range);
	}

	/**
	 * Counts sponsorship records created in the period, by type.
	 *
	 * These records represent draft-time intent, not completed signing or
	 * credit consumption.
	 *
	 * @return array<string, int> sponsorship_type => count
	 */
	public function sponsorshipMix(DateRange $range): array {
		return $this->countByColumn('gopaperless_signer_sponsorships', 's', 'sponsorship_type', 'created_at', $range);
	}

	/**
	 * Public-upload workflows created in the period, by status.
	 *
	 * @return array<string, int> status => count
	 */
	public function publicWorkflowsByStatus(DateRange $range): array {
		return $this->countByColumn('gopaperless_public_workflows', 'w', 'status', 'created_at', $range);
	}

	// ── funding attribution ──────────────────────────────────
	//
	// Consumption evidence lives in `libresign_sign_request.metadata` (JSON), so
	// it is filtered PHP-side: no JSON_EXTRACT / JSON_VALUE, DB-portable. Every
	// query is bounded in SQL (date range, or an explicit id list) BEFORE any
	// metadata is decoded, and id lists are chunked.

	/** Max ids per IN (...) list. Callers batching root ids should use the same size. */
	public const ID_CHUNK = 500;

	/**
	 * Ids of the root documents counted by completedDocumentTimes() (same
	 * roll-up, same completion window), so funding is attributed to exactly the
	 * documents the validated "completed documents" metric counts. Keep this
	 * predicate identical to completedDocumentTimes().
	 *
	 * @return list<int>
	 */
	public function completedRootIds(DateRange $range): array {
		$qb = $this->db->getQueryBuilder();
		$completedAt = $qb->createFunction('MAX(sr.signed)');
		$qb->select('r.id')
			->from('libresign_file', 'r')
			->innerJoin('r', 'libresign_file', 'df', $qb->expr()->orX(
				$qb->expr()->eq('df.id', 'r.id'),
				$qb->expr()->eq('df.parent_file_id', 'r.id'),
			))
			->innerJoin('df', 'libresign_sign_request', 'sr', $qb->expr()->eq('sr.file_id', 'df.id'))
			->where($qb->expr()->isNull('r.parent_file_id'))
			->andWhere($qb->expr()->eq('r.status', $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT)))
			->groupBy('r.id')
			->having($qb->expr()->gte($completedAt, $qb->createNamedParameter($range->startUtc, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)))
			->andHaving($qb->expr()->lt($completedAt, $qb->createNamedParameter($range->endUtcExclusive, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)));
		return array_map(static fn (int|string $id): int => (int)$id, array_column($this->rows($qb), 'id'));
	}

	/**
	 * Every sign_request of the given root documents (the root itself and its
	 * children), with the raw funding evidence. One query; callers pass at most
	 * ID_CHUNK roots.
	 *
	 * @param list<int> $rootIds
	 * @return list<array{rootId: int, signRequestId: int, sponsorshipType: string, metadata: mixed}>
	 */
	public function signRequestsOfRoots(array $rootIds): array {
		if ($rootIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$ids = $qb->createNamedParameter($rootIds, IQueryBuilder::PARAM_INT_ARRAY);
		$qb->select('sr.id', 'sr.sponsorship_type', 'sr.metadata')
			->selectAlias('df.id', 'file_id')
			->selectAlias('df.parent_file_id', 'parent_file_id')
			->from('libresign_sign_request', 'sr')
			->innerJoin('sr', 'libresign_file', 'df', $qb->expr()->eq('df.id', 'sr.file_id'))
			->where($qb->expr()->orX(
				$qb->expr()->in('df.id', $ids),
				$qb->expr()->in('df.parent_file_id', $ids),
			));
		return array_map(static fn (array $r): array => [
			'rootId' => (int)($r['parent_file_id'] ?? $r['file_id']),
			'signRequestId' => (int)$r['id'],
			'sponsorshipType' => (string)$r['sponsorship_type'],
			'metadata' => $r['metadata'],
		], $this->rows($qb));
	}

	/**
	 * Which of the given sign_requests have at least one PAID sign_request
	 * payment. gopaperless_payments.transaction_id IS the sign_request id
	 * (PaymentFactory::createPayment <- StartPaymentDTO::signRequestId), so this
	 * is a direct key lookup. DISTINCT: retries / duplicate attempts collapse.
	 *
	 * @param list<int> $signRequestIds
	 * @return array<int, true> sign_request id => true
	 */
	public function signRequestsWithPaidPayment(array $signRequestIds): array {
		$out = [];
		foreach (array_chunk($signRequestIds, self::ID_CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('p.transaction_id')
				->from('gopaperless_payments', 'p')
				->where($qb->expr()->eq('p.purpose', $qb->createNamedParameter(PaymentPurpose::SIGN_REQUEST->value)))
				->andWhere($qb->expr()->eq('p.status', $qb->createNamedParameter(PaymentStatus::PAID->value)))
				->andWhere($qb->expr()->in('p.transaction_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->column($qb, 'transaction_id') as $id) {
				$out[(int)$id] = true;
			}
		}
		return $out;
	}

	/**
	 * Entitlement id => type for the given ids. Ids that no longer exist are
	 * simply absent (callers label those FundingClassifier::UNKNOWN_POOL).
	 *
	 * @param list<int> $entitlementIds
	 * @return array<int, string>
	 */
	public function entitlementTypes(array $entitlementIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($entitlementIds)), self::ID_CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('e.id', 'e.type')
				->from('gopaperless_entitlements', 'e')
				->where($qb->expr()->in('e.id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->rows($qb) as $row) {
				$out[(int)$row['id']] = (string)$row['type'];
			}
		}
		return $out;
	}

	/**
	 * Counts consumed credits by entitlement for sign_requests signed in the period.
	 *
	 * The settlement marker is required; reservations and sponsorship records
	 * alone do not establish consumption. Since there is no consumed_at field,
	 * signed is used as the reporting date. Each settled sign_request counts as
	 * one credit, including unlimited entitlements.
	 *
	 * Results are streamed and aggregated by entitlement to limit PHP memory use.
	 *
	 * @return array<int, int> entitlement ID => count; key 0 means the
	 *                         settlement marker exists but the entitlement ID is missing.
	 */
	public function consumedByEntitlement(DateRange $range): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('sr.metadata')
			->from('libresign_sign_request', 'sr')
			->where($this->inRange($qb, 'sr.signed', $range))
			->andWhere($qb->expr()->isNotNull('sr.metadata'));

		$out = [];
		$result = $qb->executeQuery();
		try {
			while (($row = $result->fetchAssociative()) !== false) {
				$settlement = FundingClassifier::settlement($row['metadata']);
				if (!$settlement['consumed']) {
					continue;
				}
				$key = $settlement['entitlementId'] ?? 0;
				$out[$key] = ($out[$key] ?? 0) + 1;
			}
		} finally {
			$result->closeCursor();
		}
		return $out;
	}

	/**
	 * Counts requester sponsorship records created in the period and their
	 * distinct root documents. Sponsorship records represent intent, not
	 * completed signing or credit consumption.
	 *
	 * @return array{records: int, documents: int}
	 */
	public function requesterSponsorshipActivity(DateRange $range): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'records')
			->selectAlias($qb->createFunction('COUNT(DISTINCT COALESCE(f.parent_file_id, s.file_id))'), 'documents')
			->from('gopaperless_signer_sponsorships', 's')
			->leftJoin('s', 'libresign_file', 'f', $qb->expr()->eq('f.id', 's.file_id'))
			->where($qb->expr()->eq('s.sponsorship_type', $qb->createNamedParameter(SponsorshipType::REQUESTER->value)))
			->andWhere($this->inRange($qb, 's.created_at', $range));
		$row = $this->rows($qb)[0] ?? [];
		return ['records' => (int)($row['records'] ?? 0), 'documents' => (int)($row['documents'] ?? 0)];
	}

	// ── helpers ──────────────────────────────────────────────

	/**
	 * @return array<string, int>
	 */
	private function countByColumn(string $table, string $alias, string $column, string $timeColumn, DateRange $range): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select("$alias.$column")
			->selectAlias($qb->func()->count('*'), 'c')
			->from($table, $alias)
			->where($this->inRange($qb, "$alias.$timeColumn", $range))
			->groupBy("$alias.$column");
		$out = [];
		foreach ($this->rows($qb) as $row) {
			$out[(string)$row[$column]] = (int)$row['c'];
		}
		return $out;
	}

	private function purposeIn(IQueryBuilder $qb): string {
		return (string)$qb->expr()->in('p.purpose', $qb->createNamedParameter(
			array_map(static fn (PaymentPurpose $p): string => $p->value, self::PURPOSES),
			IQueryBuilder::PARAM_STR_ARRAY,
		));
	}

	private function inRange(IQueryBuilder $qb, string $column, DateRange $range): \OCP\DB\QueryBuilder\ICompositeExpression {
		return $qb->expr()->andX(
			$qb->expr()->gte($column, $qb->createNamedParameter($range->startUtc, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)),
			$qb->expr()->lt($column, $qb->createNamedParameter($range->endUtcExclusive, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function rows(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$rows = $result->fetchAllAssociative();
		$result->closeCursor();
		return $rows;
	}

	/**
	 * @return list<string>
	 */
	private function column(IQueryBuilder $qb, string $name): array {
		return array_map(static fn (array $r): string => (string)$r[$name], $this->rows($qb));
	}

	private function scalar(IQueryBuilder $qb): int {
		$rows = $this->rows($qb);
		return (int)($rows[0]['c'] ?? 0);
	}
}
