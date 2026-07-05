<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Db;

use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Service\Payment\Exceptions\PaymentNotFoundException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\DB\Types;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Psr\Log\LoggerInterface;

class PaymentMapper extends QBMapper
{

	public function __construct(
		IDBConnection $db,
		protected LoggerInterface $logger,
	) {
		parent::__construct($db, 'gopaperless_payments', Payment::class);
	}

	/**
	 * Find payment by Payment ID.
	 * @throws Exception
	 */
	public function findById(int $id): ?Payment
	{

		$qb = $this->db->getQueryBuilder();

		$qb->select('p.*')
			->from($this->getTableName(), 'p')
			->where(
				$qb->expr()->eq(
					'p.id',
					$qb->createNamedParameter($id, Types::INTEGER)
				)
			)
			->setMaxResults(1);

		try {
			/** @var Payment $entity */
			$entity = $this->findEntity($qb);
			return $entity;
		} catch (DoesNotExistException | MultipleObjectsReturnedException) {
			return null;
		}
	}

	/**
	 * Find payment by provider reference.
	 */
	public function findByProviderReference(string $reference): ?Payment
	{

		$qb = $this->db->getQueryBuilder();

		$qb->select('p.*')
			->from($this->getTableName(), 'p')
			->where(
				$qb->expr()->eq(
					'p.provider_reference',
					$qb->createNamedParameter($reference, Types::STRING)
				)
			)
			->setMaxResults(1);

		try {
			/** @var Payment $entity */
			$entity = $this->findEntity($qb);
			return $entity;
		} catch (DoesNotExistException | MultipleObjectsReturnedException) {
			return null;
		}
	}

	/**
	 * Find payment by provider + reference
	 */
	public function findByProviderAndReference(PaymentProvider $provider, string $reference): ?Payment
	{

		$qb = $this->db->getQueryBuilder();

		$qb->select('p.*')
			->from($this->getTableName(), 'p')
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq(
						'p.provider',
						$qb->createNamedParameter($provider->value, Types::STRING)
					),
					$qb->expr()->eq(
						'p.provider_reference',
						$qb->createNamedParameter($reference, Types::STRING)
					)
				)
			)
			->setMaxResults(1);

		try {
			/** @var Payment $entity */
			$entity = $this->findEntity($qb);
			return $entity;
		} catch (DoesNotExistException | MultipleObjectsReturnedException) {
			return null;
		}
	}

	/**
	 * Find payment by payment attempt id (idempotency protection).
	 */
	public function findByAttemptId(string $attemptId): ?Payment
	{

		$qb = $this->db->getQueryBuilder();

		$qb->select('p.*')
			->from($this->getTableName(), 'p')
			->where(
				$qb->expr()->eq(
					'p.payment_attempt_id',
					$qb->createNamedParameter($attemptId, Types::STRING)
				)
			)
			->setMaxResults(1);

		try {
			/** @var Payment $entity */
			$entity = $this->findEntity($qb);
			return $entity;
		} catch (DoesNotExistException | MultipleObjectsReturnedException) {
			return null;
		}
	}

	/**
	 * Find all payments for a given user.
	 *
	 * Useful for:
	 * - auditing payments per user
	 * - debugging entitlement issues
	 * - future account/payment history features
	 *
	 * @return Payment[]
	 * @throws Exception
	 */
	public function findAllByUserId(string $userId): array
	{

		$qb = $this->db->getQueryBuilder();

		$qb->select('p.*')
			->from($this->getTableName(), 'p')
			->where(
				$qb->expr()->eq(
					'p.user_id',
					$qb->createNamedParameter($userId, Types::STRING)
				)
			)
			->orderBy('p.created_at', 'DESC'); // newest first (important)

		return $this->findEntities($qb);
	}

	/**
	 * Find all payments for a given transaction.
	 *
	 * @return Payment[]
	 * @throws Exception
	 */
	public function findAllByTransactionId(int $transactionId): array
	{

		$qb = $this->db->getQueryBuilder();

		$qb->select('p.*')
			->from($this->getTableName(), 'p')
			->where(
				$qb->expr()->eq(
					'p.transaction_id',
					$qb->createNamedParameter($transactionId, Types::INTEGER)
				)
			)
			->orderBy('p.created_at', 'DESC'); // newest first

		return $this->findEntities($qb);
	}


	/**
	 * Find latest payment attempt for a transaction.
	 *
	 * Useful for:
	 * - orchestration recovery
	 * - resume flows
	 * - determining current payment state
	 * - dashboard activity timelines
	 *
	 * @throws Exception
	 */
	public function findLatestByTransactionId(
		int $transactionId
	): ?Payment {

		$qb = $this->db->getQueryBuilder();

		$qb->select('p.*')
			->from($this->getTableName(), 'p')
			->where(
				$qb->expr()->eq(
					'p.transaction_id',
					$qb->createNamedParameter(
						$transactionId,
						Types::INTEGER
					)
				)
			)
			->orderBy('p.created_at', 'DESC')
			->setMaxResults(1);

		try {

			/** @var Payment $entity */
			$entity = $this->findEntity($qb);

			return $entity;
		} catch (
			DoesNotExistException |
			MultipleObjectsReturnedException) {

			return null;
		}
	}


	/**
	 * Find latest successful (PAID) payment per transaction.
	 *
	 * @throws Exception
	 */
	public function findLatestPaidByTransactionId(int $transactionId): ?Payment
	{

		$qb = $this->db->getQueryBuilder();

		$qb->select('p.*')
			->from($this->getTableName(), 'p')
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq(
						'p.transaction_id',
						$qb->createNamedParameter($transactionId, Types::INTEGER)
					),
					$qb->expr()->eq(
						'p.status',
						$qb->createNamedParameter($this->status(PaymentStatus::PAID), Types::STRING)
					)
				)
			)
			->orderBy('p.created_at', 'DESC') // newest first
			->setMaxResults(1);

		try {
			/** @var Payment $entity */
			$entity = $this->findEntity($qb);
			return $entity;
		} catch (DoesNotExistException | MultipleObjectsReturnedException) {
			return null;
		}
	}

	/**
	 * Find latest pending payment per transaction.
	 *
	 * @throws Exception
	 */
	public function findLatestPendingByTransactionId(
		int $transactionId
	): ?Payment {

		$qb = $this->db->getQueryBuilder();

		$qb->select('p.*')
			->from($this->getTableName(), 'p')
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq(
						'p.transaction_id',
						$qb->createNamedParameter(
							$transactionId,
							Types::INTEGER
						)
					),
					$qb->expr()->eq(
						'p.status',
						$qb->createNamedParameter(
							$this->status(PaymentStatus::PENDING),
							Types::STRING
						)
					)
				)
			)
			->orderBy('p.created_at', 'DESC')
			->setMaxResults(1);

		try {

			/** @var Payment $entity */
			$entity = $this->findEntity($qb);

			return $entity;
		} catch (
			DoesNotExistException |
			MultipleObjectsReturnedException) {

			return null;
		}
	}


	/**
	 * Find latest failed payment attempt for a transaction.
	 *
	 * @throws Exception
	 */
	public function findLatestFailedByTransactionId(
		int $transactionId
	): ?Payment {

		$qb = $this->db->getQueryBuilder();

		$qb->select('p.*')
			->from($this->getTableName(), 'p')
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq(
						'p.transaction_id',
						$qb->createNamedParameter(
							$transactionId,
							Types::INTEGER
						)
					),
					$qb->expr()->eq(
						'p.status',
						$qb->createNamedParameter(
							$this->status(PaymentStatus::FAILED),
							Types::STRING
						)
					)
				)
			)
			->orderBy('p.created_at', 'DESC')
			->setMaxResults(1);

		try {

			/** @var Payment $entity */
			$entity = $this->findEntity($qb);

			return $entity;
		} catch (
			DoesNotExistException |
			MultipleObjectsReturnedException) {

			return null;
		}
	}


	/**
	 * Find latest expired payment attempt for a transaction.
	 *
	 * @throws Exception
	 */
	public function findLatestExpiredByTransactionId(
		int $transactionId
	): ?Payment {

		$qb = $this->db->getQueryBuilder();

		$qb->select('p.*')
			->from($this->getTableName(), 'p')
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq(
						'p.transaction_id',
						$qb->createNamedParameter(
							$transactionId,
							Types::INTEGER
						)
					),
					$qb->expr()->eq(
						'p.status',
						$qb->createNamedParameter(
							$this->status(PaymentStatus::EXPIRED),
							Types::STRING
						)
					)
				)
			)
			->orderBy('p.created_at', 'DESC')
			->setMaxResults(1);

		try {

			/** @var Payment $entity */
			$entity = $this->findEntity($qb);

			return $entity;
		} catch (
			DoesNotExistException |
			MultipleObjectsReturnedException) {

			return null;
		}
	}


	/**
	 * Find all payments by status.
	 * used for dashboards, cron, reconciliation batches, admin tooling, observability
	 * @return Payment[]
	 * @throws Exception
	 */
	public function findAllByStatus(
		PaymentStatus $status,
		int $limit = 100
	): array {

		$qb = $this->db->getQueryBuilder();

		$qb->select('p.*')
			->from($this->getTableName(), 'p')
			->where(
				$qb->expr()->eq(
					'p.status',
					$qb->createNamedParameter(
						$status->value,
						Types::STRING
					)
				)
			)
			->orderBy('p.created_at', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Find all payments by provider.
	 *
	 * @return Payment[]
	 * @throws Exception
	 */
	public function findAllByProvider(
		PaymentProvider $provider,
		int $limit = 100
	): array {

		$qb = $this->db->getQueryBuilder();

		$qb->select('p.*')
			->from($this->getTableName(), 'p')
			->where(
				$qb->expr()->eq(
					'p.provider',
					$qb->createNamedParameter(
						$provider->value,
						Types::STRING
					)
				)
			)
			->orderBy('p.created_at', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Find payments with stale verification locks.
	 *
	 * @return Payment[]
	 * @throws Exception
	 */
	public function findStaleVerificationLocks(
		int $timeoutSeconds = 600,
		int $limit = 100
	): array {

		$qb = $this->db->getQueryBuilder();

		$cutoff = (new \DateTimeImmutable(
			'now',
			new \DateTimeZone('UTC')
		))->modify("-{$timeoutSeconds} seconds");

		$qb->select('p.*')
			->from($this->getTableName(), 'p')
			->where(
				$qb->expr()->isNotNull(
					'p.verification_locked_at'
				)
			)
			->andWhere(
				$qb->expr()->lte(
					'p.verification_locked_at',
					$qb->createNamedParameter(
						$cutoff->format('Y-m-d H:i:s')
					)
				)
			)
			->orderBy('p.verification_locked_at', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}


	/**
	 * Find expired pending payments.
	 *
	 * @return Payment[]
	 * @throws Exception
	 */
	public function findExpiredPendingPayments(
		int $limit = 100
	): array {

		$qb = $this->db->getQueryBuilder();

		$now = new \DateTimeImmutable(
			'now',
			new \DateTimeZone('UTC')
		);

		$qb->select('p.*')
			->from($this->getTableName(), 'p')
			->where(
				$qb->expr()->eq(
					'p.status',
					$qb->createNamedParameter(
						PaymentStatus::PENDING->value,
						Types::STRING
					)
				)
			)
			->andWhere(
				$qb->expr()->isNotNull('p.expires_at')
			)
			->andWhere(
				$qb->expr()->lte(
					'p.expires_at',
					$qb->createNamedParameter(
						$now->format('Y-m-d H:i:s')
					)
				)
			)
			->orderBy('p.expires_at', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}


	/**
	 * Determine whether transaction has a paid payment.
	 *
	 * @throws Exception
	 */
	public function existsPaidByTransactionId(
		int $transactionId
	): bool {

		return $this->findLatestPaidByTransactionId(
			$transactionId
		) !== null;
	}


	/**
	 * Fetch payments that require provider verification (background job).
	 *
	 * RULES:
	 * - Only includes providers that support verification (e.g. DPO)
	 * - Excludes callback-driven providers (e.g. Daraja)
	 * - Requires provider_reference (cannot verify without it)
	 * - Respects retry scheduling (next_verification_at)
	 * - Skips locked rows to avoid concurrent processing
	 *
	 * NOTE:
	 * This is used exclusively by the background verification job.
	 *
	 * @return Payment[]
	 */
	public function findPendingForVerification(int $limit = 100): array
	{
		$qb = $this->db->getQueryBuilder();

		$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

		$this->logger->info('[VerifyPayments] provider filter', [
			'providers' => PaymentProvider::verifiableValues(),
		]);

		$qb->select('p.*')
			->from($this->getTableName(), 'p')
			->where(
				$qb->expr()->eq(
					'p.status',
					$qb->createNamedParameter(PaymentStatus::PENDING->value)
				)
			)
			->andWhere(
				$qb->expr()->in(
					'p.provider',
					$qb->createNamedParameter(
						PaymentProvider::verifiableValues(),
						IQueryBuilder::PARAM_STR_ARRAY
					)
				)
			)
			->andWhere(
				$qb->expr()->isNotNull('p.provider_reference')
			)
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('p.next_verification_at'),
					$qb->expr()->lte(
						'p.next_verification_at',
						$qb->createNamedParameter($now->format('Y-m-d H:i:s'))
					)
				)
			)
			->andWhere(
				$qb->expr()->isNull('p.verification_locked_at')
			)
			->orderBy('p.next_verification_at', 'ASC')
			->addOrderBy('p.created_at', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}


	/**
	 * Find latest pending credit purchase payment for a user.
	 *
	 * Used for:
	 * - resumable credit purchase flows
	 * - preventing duplicate pending purchases
	 * - restoring interrupted checkout sessions
	 *
	 * IMPORTANT:
	 * Only applies to CREDIT_PURCHASE purpose.
	 *
	 * @throws Exception
	 */
	public function findLatestPendingCreditPurchaseByUserId(
		string $userId,
		string $productCode
	): ?Payment {

		$qb = $this->db->getQueryBuilder();

		$qb->select('p.*')
			->from($this->getTableName(), 'p')
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq(
						'p.user_id',
						$qb->createNamedParameter(
							$userId,
							Types::STRING
						)
					),
					$qb->expr()->eq(
						'p.product_code',
						$qb->createNamedParameter(
							$productCode,
							Types::STRING
						)
					),
					$qb->expr()->eq(
						'p.purpose',
						$qb->createNamedParameter(
							PaymentPurpose::CREDIT_PURCHASE->value,
							Types::STRING
						)
					),
					$qb->expr()->eq(
						'p.status',
						$qb->createNamedParameter(
							PaymentStatus::PENDING->value,
							Types::STRING
						)
					)
				)
			)
			->orderBy('p.created_at', 'DESC')
			->setMaxResults(1);

		try {

			/** @var Payment $entity */
			$entity = $this->findEntity($qb);

			return $entity;
		} catch (
			DoesNotExistException |
			MultipleObjectsReturnedException) {

			return null;
		}
	}


	/**
	 * Attempt to acquire a verification lock for a payment.
	 *
	 * Used to prevent concurrent verification workers from processing
	 * the same payment simultaneously.
	 *
	 * Returns:
	 * - true  → lock acquired successfully
	 * - false → payment is already locked by another worker
	 *
	 * IMPORTANT:
	 * This operation is atomic at the database level.
	 *
	 * @throws Exception
	 */
	public function tryLockVerification(int $paymentId): bool
	{
		$qb = $this->db->getQueryBuilder();

		$now = new \DateTimeImmutable(
			'now',
			new \DateTimeZone('UTC')
		);

		$qb->update($this->getTableName())
			->set(
				'verification_locked_at',
				$qb->createNamedParameter(
					$now->format('Y-m-d H:i:s')
				)
			)
			->where(
				$qb->expr()->eq(
					'id',
					$qb->createNamedParameter(
						$paymentId,
						Types::INTEGER
					)
				)
			)
			->andWhere(
				$qb->expr()->isNull(
					'verification_locked_at'
				)
			);

		return $qb->executeStatement() === 1;
	}

	/**
	 * Find a payment by provider reference, fully rehydrated, or throw.
	 *
	 * Rehydration (findById after the initial lookup) works around a
	 * Nextcloud ORM quirk where the first finder can return a partially
	 * hydrated or unmanaged entity; re-fetching by id guarantees a clean,
	 * managed entity safe to mutate.
	 *
	 * Throws when no payment matches — callers operate on a reference the
	 * client asserts exists, so absence is an error, not a normal outcome.
	 * Use findByProviderReference() directly when you want a nullable result.
	 *
	 * @throws PaymentNotFoundException
	 */
	public function findByProviderReferenceOrFail(string $reference): Payment
	{
		$payment = $this->findByProviderReference($reference);

		if (!$payment) {
			throw new PaymentNotFoundException();
		}

		$rehydrated = $this->findById($payment->getId());

		if (!$rehydrated) {
			throw new PaymentNotFoundException();
		}

		return $rehydrated;
	}

	private function status(PaymentStatus $status): string
	{
		return $status->value;
	}
}
