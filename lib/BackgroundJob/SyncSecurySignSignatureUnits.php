<?php

declare(strict_types=1);

namespace OCA\Libresign\BackgroundJob;

use OCA\Libresign\Service\Payment\SignatureUnitOutbox;
use OCA\Libresign\Service\SecurySignService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Sends settled SecurySign signing-credit purchases to Signa's unit ledger. */
class SyncSecurySignSignatureUnits extends TimedJob {
	private const BATCH = 100;

	public function __construct(
		ITimeFactory $time,
		private SignatureUnitOutbox $outbox,
		private SecurySignService $securySign,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60);
	}

	protected function run($argument): void {
		foreach ($this->outbox->pending(self::BATCH) as $grant) {
			try {
				$this->securySign->grantSignatureUnits(
					$grant['user_key'],
					(int)$grant['units'],
					$grant['grant_key'],
				);
				$this->outbox->markSynced((int)$grant['payment_id']);
			} catch (\Throwable $e) {
				$this->logger->warning('SecurySign signature-unit grant deferred', [
					'payment_id' => $grant['payment_id'],
					'exception' => $e,
				]);
			}
		}
	}
}
