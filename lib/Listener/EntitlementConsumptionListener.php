<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Listener;

use OCA\Libresign\Enum\ProductCode;
use OCA\Libresign\Enum\SettlementSource;
use OCA\Libresign\Events\SignedEvent;
use OCA\Libresign\Exception\EntitlementAlreadyConsumedException;
use OCA\Libresign\Service\Sponsorship\SignerResolverService;
use OCA\Libresign\Service\Sponsorship\SigningSettlementService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Settles entitlements following successful signing.
 *
 * The signer is resolved independently of SignedEvent::getUser(), which
 * represents the file owner and may not be the signer. Unresolved identities
 * are not settled.
 *
 * Settlement failures cannot invalidate an already completed signature.
 * They are logged for recovery through reconciliation.
 *
 * @template-implements IEventListener<SignedEvent>
 */
class EntitlementConsumptionListener implements IEventListener {
	private const LOG_PREFIX = '[EntitlementSettlement][EntitlementConsumptionListener]';

	public function __construct(
		private SignerResolverService $signerResolver,
		private SigningSettlementService $settlementService,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof SignedEvent) {
			return;
		}

		$signRequestId = $event->getSignRequest()->getId();

		$this->logger->debug(
			self::LOG_PREFIX . ' SignedEvent received; resolving signer for settlement.',
			[
				'signRequestId' => $signRequestId,
				'eventType' => SignedEvent::class,
			],
		);

		$resolution = $this->signerResolver->resolve($signRequestId);

		if (!$resolution->resolved) {
			$this->logger->warning(
				self::LOG_PREFIX . ' Signer unresolved; no settlement attempted.',
				[
					'signRequestId' => $signRequestId,
					'eventType' => SignedEvent::class,
					'outcome' => $resolution->reason,
				],
			);
			return;
		}

		$this->logger->debug(
			self::LOG_PREFIX . ' Signer resolved; delegating to settlement.',
			[
				'signRequestId' => $signRequestId,
				'signerUserId' => $resolution->userId,
				'productCode' => ProductCode::SIGN_DOCUMENT->value,
			],
		);

		try {
			$this->settlementService->settle(
				signRequestId: $signRequestId,
				signerUserId: $resolution->userId,
				productCode: ProductCode::SIGN_DOCUMENT->value,
				source: SettlementSource::SIGNED_EVENT_LISTENER,
			);

			$this->logger->info(
				self::LOG_PREFIX . ' Settlement completed via listener.',
				[
					'signRequestId' => $signRequestId,
					'signerUserId' => $resolution->userId,
					'outcome' => 'committed',
				],
			);
		} catch (EntitlementAlreadyConsumedException $e) {
			// Settlement was already committed. Log the skip at this boundary so
			// listener-level outcomes remain distinguishable in operational logs.
			$this->logger->info(
				self::LOG_PREFIX . ' Settlement skipped by listener: sign request was already settled.',
				[
					'signRequestId' => $signRequestId,
					'signerUserId' => $resolution->userId,
					'outcome' => 'skipped_already_consumed',
				],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				self::LOG_PREFIX . ' Settlement failed.',
				[
					'signRequestId' => $signRequestId,
					'signerUserId' => $resolution->userId,
					'exceptionClass' => get_class($e),
					'exception' => $e,
					'outcome' => 'failed',
				],
			);
		}
	}
}
