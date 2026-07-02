<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Entitlement;

use OCA\Libresign\Db\Entitlement;
use OCA\Libresign\Db\EntitlementMapper;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Service\Product\ProductService;
use OCP\DB\Exception;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use RuntimeException;

class EntitlementService {

	private EntitlementMapper $entitlementMapper;
	private SignRequestMapper $signRequestMapper;
	private LoggerInterface $logger;
	private IDBConnection $db;
	private ProductService $productService;

	public function __construct(
		EntitlementMapper $entitlementMapper,
		SignRequestMapper $signRequestMapper,
		IDBConnection $db,
		ProductService $productService,
		LoggerInterface $logger,
	) {
		$this->entitlementMapper = $entitlementMapper;
		$this->signRequestMapper = $signRequestMapper;
		$this->productService = $productService;
		$this->db = $db;
		$this->logger = $logger;
	}

	/**
	 * Create entitlement after successful payment
	 *
	 * Minimal implementation:
	 * - 1 payment → 1 use
	 * @throws Exception
	 * @throws \Exception
	 */
	public function create(string $userId, string $productCode, int $uses = 1): Entitlement {

		$entitlement = new Entitlement();
		$entitlement->setUserId($userId);
		$entitlement->setProductCode($productCode);
		$entitlement->setRemainingUses($uses);
		$entitlement->setCreatedAt($this->now());
		$entitlement->validate();

		/** @var Entitlement $inserted */
		$inserted = $this->entitlementMapper->insert($entitlement);
		return $inserted;
	}

	/**
	 * Get a usable entitlement (does NOT consume)
	 *
	 * Returns the FIRST valid entitlement (FIFO)
	 * @throws Exception
	 */
	public function getValid(string $userId, string $productCode): ?Entitlement {

		if ($userId === '' || $productCode === '') {
			throw new RuntimeException('[EntitlementService] Invalid entitlement lookup parameters');
		}

		$entitlements = $this->entitlementMapper
			->findByUserAndProduct($userId, $productCode);

		foreach ($entitlements as $entitlement) {
			if ($entitlement->canUse()) {
				return $entitlement;
			}
		}

		return null;
	}

	/**
	 * TEMPORARY WORKAROUND (POST-SIGN ENTITLEMENT CONSUMPTION)
	 *
	 * Due to Nextcloud DB constraints ("dirty table reads") triggered by
	 * dispatchSignedEvent() during signing, entitlement consumption cannot
	 * reliably occur inside the signing flow.
	 *
	 * Current approach:
	 * - FE calls this endpoint AFTER receiving 200 from sign request
	 * - We verify the file is actually signed (source of truth)
	 * - Consumption is idempotent via metadata flag
	 * - Wrapped in transaction to avoid partial state
	 *
	 * NOTE:
	 * This is a temporary solution for stability.
	 * Proper fix: move event dispatch to async/background job or after DB commit.
	 * @throws Exception
	 */
	public function consumeAfterSign(
		string $userId,
		string $signUuid,
		string $productCode,
		int $signRequestId,
	): bool {
		$this->db->beginTransaction();

		try {

			$this->logger->info('[EntitlementService] consumeAfterSign called', [
				'userId' => $userId,
				'signUuid' => $signUuid,
				'signRequestId' => $signRequestId,
				'productCode' => $productCode,
			]);

			// 1 Resolve sign UUID from sign request ID if the frontend did not provide it
			if (($signUuid === '' || $signUuid === '0') && $signRequestId > 0) {
				try {
					$resolved = $this->signRequestMapper->getById($signRequestId);
					$signUuid = $resolved->getUuid();

					$this->logger->info('[EntitlementService] resolved signUuid from signRequestId', [
						'signRequestId' => $signRequestId,
						'signUuid' => $signUuid,
					]);
				} catch (\Throwable $e) {
					$this->logger->error('[EntitlementService] could not resolve sign request by ID', [
						'signRequestId' => $signRequestId,
						'error' => $e->getMessage(),
					]);
					throw new \RuntimeException('Invalid sign request ID');
				}
			}

			if ($signUuid === '' || $signUuid === '0') {
				throw new \RuntimeException('signUuid is required');
			}

			// 2 Fetch sign request (fresh)
			$signRequest = $this->signRequestMapper->getByUuidUncached($signUuid);

			// 3 Validate request integrity
			if ($signRequest->getId() !== $signRequestId) {
				$this->logger->warning('[EntitlementService] Invalid sign request', [
					'userId' => $userId,
					'signUuid' => $signUuid,
					'signRequestId' => $signRequestId,
					'productCode' => $productCode,
				]);
				throw new \RuntimeException('Invalid sign request');
			}

			// 4 Ensure signed
			if ($signRequest->getSigned() === null) {
				$this->logger->warning('[EntitlementService] File is not signed yet', [
					'userId' => $userId,
					'signUuid' => $signUuid,
					'signRequestId' => $signRequestId,
					'productCode' => $productCode,
				]);
				throw new \RuntimeException('File is not signed yet');
			}

			// 5 Metadata (idempotency)
			$metadata = $signRequest->getMetadata() ?? [];

			if (!empty($metadata['entitlement_consumed'])) {
				$this->logger->info('[EntitlementService] already consumed', [
					'signRequestId' => $signRequestId,
					'userId' => $userId,
					'productCode' => $productCode,
					'entitlement_consumed' => $metadata['entitlement_consumed'],
				]);
				$this->db->commit();
				return false; // already consumed
			}

			// 6 Validate product (BE source of truth)
			$product = $this->productService->getDefaultByCode($productCode);
			$productCode = $product->getCode();

			// 7 Get user entitlement
			$entitlement = $this->getValid($userId, $productCode);

			if (!$entitlement) {
				$this->logger->warning('[EntitlementService] no valid entitlement', [
					'userId' => $userId,
					'productCode' => $productCode,
					'signRequestId' => $signRequestId,
				]);
				throw new \RuntimeException('No valid entitlement available');
			}

			// 8 Consume
			$entitlement->consume();
			$this->entitlementMapper->update($entitlement);

			// 9 Mark consumed
			$metadata['entitlement_consumed'] = true;
			$metadata['productCode'] = $productCode;

			$signRequest->setMetadata($metadata);
			$this->signRequestMapper->update($signRequest);

			$this->db->commit();
			$this->logger->info('[EntitlementService] consumed successfully', [
				'userId' => $userId,
				'productCode' => $productCode,
				'signRequestId' => $signRequestId,
				'remainingUses' => $entitlement->getRemainingUses(),
			]);

			return true;

		} catch (\Throwable $e) {
			$this->db->rollBack();
			$this->logger->error('[EntitlementService] consumption failed', [
				'exception' => $e,
				'userId' => $userId,
				'signUuid' => $signUuid,
				'signRequestId' => $signRequestId
			]);
			throw $e;
		}
	}

	/**
	 * Check if user can perform action
	 * @throws Exception
	 */
	public function canUse(string $userId, string $productCode): bool {
		return $this->getValid($userId, $productCode) !== null;
	}

	/**
	 * Consume entitlement safely
	 *
	 * This is what you call BEFORE signing
	 * @throws Exception
	 */
	public function consume(string $userId, string $productCode): Entitlement {
		/**
		 * ⚠️ NON-ATOMIC OPERATION (INTENTIONAL)
		 *
		 * Entitlement consumption is currently NOT wrapped in a transaction.
		 *
		 * FLOW:
		 * - Read entitlement
		 * - Decrement remaining uses
		 * - Persist update
		 *
		 * RISK:
		 * Concurrent requests may consume the same entitlement multiple times
		 *
		 * WHY ACCEPTABLE:
		 * - Low concurrency environment
		 * - Single-user interaction model
		 *
		 * FUTURE:
		 * Will be upgraded to transactional or locking approach if needed
		 */
		$entitlement = $this->getValid($userId, $productCode);

		if (!$entitlement) {
			throw new RuntimeException('No valid entitlement available');
		}

		$entitlement->consume();

		$this->entitlementMapper->update($entitlement);

		return $entitlement;
	}

	/**
	 * Get the user's available credit balance for a product.
	 *
	 * Entitlements are additive. A user may own multiple entitlement
	 * records for the same product due to multiple purchases.
	 *
	 * This method aggregates all currently usable entitlements and returns
	 * a summarized balance suitable for UI display and purchase flows.
	 *
	 * Example:
	 *
	 * Entitlement A -> 5 remaining uses
	 * Entitlement B -> 10 remaining uses
	 * Entitlement C -> expired
	 *
	 * Result:
	 * - remainingUses = 15
	 * - activeEntitlements = 2
	 * - canUse = true
	 *
	 * NOTE:
	 * This is intentionally a summary projection rather than returning
	 * individual entitlement records. The UI only cares about available
	 * credits, not how those credits are distributed across purchases.
	 *
	 * FUTURE:
	 * If subscriptions, sponsorships, promotional credits, or other
	 * entitlement sources are introduced, this method may evolve into a
	 * dedicated balance projection/DTO without affecting callers.
	 *
	 * @return array{
	 *     productCode: string,
	 *     remainingUses: int,
	 *     activeEntitlements: int,
	 *     canUse: bool
	 * }
	 */
	public function getAvailableCredits(
		string $userId,
		string $productCode,
	): array {

		$product = $this->productService->getDefaultByCode($productCode);

		$productCode = $product->getCode();

		$entitlements = $this->entitlementMapper
			->findByUserAndProduct($userId, $productCode);

		$remainingUses = 0;
		$activeEntitlements = 0;

		foreach ($entitlements as $entitlement) {
			if (!$entitlement->canUse()) {
				continue;
			}

			$remainingUses += $entitlement->getRemainingUses();
			$activeEntitlements++;
		}

		return [
			'productCode' => $productCode,
			'remainingUses' => $remainingUses,
			'activeEntitlements' => $activeEntitlements,
			'canUse' => $remainingUses > 0,
		];
	}

	/**
	 * @throws \Exception
	 */
	private function now(): string {
		return (new \DateTimeImmutable(
			'now',
			new \DateTimeZone('UTC'),
		))->format(DATE_ATOM);
	}
}
