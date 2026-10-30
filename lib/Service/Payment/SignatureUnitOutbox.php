<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\Payment;
use OCA\Libresign\Enum\PaymentPurpose;
use OCP\DB\Types;
use OCP\IConfig;
use OCP\IDBConnection;

/** Keeps a settled credit purchase until SecurySign confirms its grant. */
class SignatureUnitOutbox {
	private const TABLE = 'gopaperless_signa_units';

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
	) {
	}

	/** Called inside the payment finalizer's transaction. */
	public function enqueue(Payment $payment): void {
		if ($payment->getPaymentPurpose() !== PaymentPurpose::CREDIT_PURCHASE
			|| $payment->getProductCode() !== 'SIGN_DOCUMENT'
			|| $payment->getProductUses() <= 0) {
			return;
		}
		$uid = $payment->getUserId();
		if ($uid === null || $uid === '') {
			return;
		}
		$subject = $this->config->getUserValue($uid, Application::APP_ID, 'securysign_sub');
		if ($subject === '') {
			return; // The built-in signing engine has no SecurySign account to credit.
		}
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'payment_id' => $qb->createNamedParameter((int)$payment->getId(), Types::BIGINT),
			'user_key' => $qb->createNamedParameter('kc:' . $subject),
			'units' => $qb->createNamedParameter($payment->getProductUses(), Types::INTEGER),
			'grant_key' => $qb->createNamedParameter('gp-sign-' . substr(hash('sha256', $payment->getPaymentAttemptId()), 0, 54)),
		]);
		$qb->executeStatement();
	}

	/** @return list<array{payment_id: int|string, user_key: string, units: int|string, grant_key: string}> */
	public function pending(int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('payment_id', 'user_key', 'units', 'grant_key')
			->from(self::TABLE)
			->where($qb->expr()->isNull('synced_at'))
			->orderBy('payment_id', 'ASC')
			->setMaxResults($limit);
		return $qb->executeQuery()->fetchAllAssociative();
	}

	public function markSynced(int $paymentId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('synced_at', $qb->createNamedParameter(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), Types::DATETIME))
			->where($qb->expr()->eq('payment_id', $qb->createNamedParameter($paymentId, Types::BIGINT)))
			->andWhere($qb->expr()->isNull('synced_at'));
		$qb->executeStatement();
	}
}
