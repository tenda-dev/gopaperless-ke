<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship;

use OCA\Libresign\Db\File;
use OCA\Libresign\Db\SignRequest as SignRequestEntity;
use OCA\Libresign\Enum\FileStatus;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\Sponsorship\DTO\IncomingSignerDTO;
use OCP\AppFramework\Http;
use Psr\Log\LoggerInterface;

/**
 * Validates whether a sponsorship update may proceed.
 *
 * Responsibilities:
 * - Detect sponsorship changes.
 * - Validate requester entitlement coverage.
 * - Throw a validation exception when insufficient credits exist.
 *
 * This service performs no persistence.
 */
final class SponsorshipValidationService
{
	public function __construct(
		private SponsorshipChangeDetectionService $changeDetectionService,
		private SponsorshipCoverageValidatorService $coverageValidatorService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Validate an incoming sponsorship update.
	 *
	 * @param IncomingSignerDTO[] $incomingSigners
	 * @param SignRequestEntity[] $persistedSignRequests
	 *
	 * @throws LibresignException
	 */
	public function validate(
		string $requesterUserId,
		string $productCode,
		array $incomingSigners,
		array $persistedSignRequests,
		File $file,
	): void {

		if (empty($requesterUserId)) {
			throw new LibresignException(
				'Missing requester user ID for sponsorship validation.',
				Http::STATUS_BAD_REQUEST,
			);
		}

		$changes = $this->changeDetectionService->detect(
			$file,
			$incomingSigners,
			$persistedSignRequests,
		);

		$this->logger->warning('[SPONSORSHIP RECONCILIATION] Changes', [
			'count' => count($changes),
		]);


		foreach ($changes as $change) {
			$this->logger->warning('[SPONSORSHIP RECONCILIATION] Change', [
				'id' => $change->signer()->getSignRequestId(),
				'previous' => $change->previousSponsorshipType()?->value,
				'requested' => $change->requestedSponsorshipType()->value,
				'new' => $change->isNewSigner(),
			]);
		}

		$result = $this->coverageValidatorService->validate(
			$productCode,
			$requesterUserId,
			$changes,
		);

		if ($result->isAllowed()) {
			return;
		}

		throw new LibresignException(
			json_encode([
				'action' => 'purchase_signing_credits',
				'errors' => [[
					'message' => sprintf(
						'You need %d additional signing credit(s).',
						$result->getMissingCredits(),
					),
					'requiredCredits' => $result->getRequiredCredits(),
					'availableCredits' => $result->getAvailableCredits(),
					'missingCredits' => $result->getMissingCredits(),
					'blockingSignRequestIds' => $result->getBlockingSignRequestIds(),
				]],
			]),
			Http::STATUS_UNPROCESSABLE_ENTITY,
		);
	}
}
