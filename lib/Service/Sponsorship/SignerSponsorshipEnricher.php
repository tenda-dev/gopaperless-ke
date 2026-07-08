<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship;

use OCA\Libresign\Db\SignerSponsorship;
use OCA\Libresign\Db\SignerSponsorshipMapper;
use OCA\Libresign\Enum\SponsorshipType;

/**
 * Enriches signer view models with sponsorship information.
 *
 * Signer data is primarily loaded from SignRequest records. Sponsorship
 * information lives in a separate persistence model, so this class
 * composes both datasets into a single representation for API responses.
 *
 * The enricher accepts either array-based or object-based signer payloads,
 * allowing it to be reused across different response builders without
 * coupling those builders to sponsorship persistence.
 */
final class SignerSponsorshipEnricher
{
	public function __construct(
		private SignerSponsorshipMapper $mapper,
	) {}

	/**
	 * Hydrates signer payloads with sponsorship metadata.
	 *
	 * Callers may optionally provide a preloaded sponsorship map to avoid
	 * repeated database lookups when enriching multiple signer collections.
	 *
	 * @param array<int, array|object> $signers
	 * @param array<int, SignerSponsorship>|null $sponsorshipMap
	 *
	 * @return array<int, array|object>
	 */
	public function enrich(
		array $signers,
		?array $sponsorshipMap = null,
	): array {

		if ($signers === []) {
			return $signers;
		}

		$sponsorshipMap ??= $this->loadSponsorshipMap($signers);

		foreach ($signers as $key => $signer) {
			$id = $this->readSignRequestId($signer);

			$signers[$key] = $this->applySponsorship(
				$signer,
				$id !== null ? ($sponsorshipMap[$id] ?? null) : null,
			);
		}

		return $signers;
	}

	/**
	 * Loads sponsorships indexed by sign request ID.
	 *
	 * Intended for callers that want to preload sponsorship information once
	 * and reuse it across multiple enrichment operations.
	 *
	 * @param array<int, array|object> $signers
	 *
	 * @return array<int, SignerSponsorship>
	 */
	public function loadSponsorshipMap(array $signers): array
	{
		$signRequestIds = [];

		foreach ($signers as $signer) {
			$id = $this->readSignRequestId($signer);

			if ($id !== null) {
				$signRequestIds[] = $id;
			}
		}

		return $this->loadSponsorshipMapBySignRequestIds(
			$signRequestIds,
		);
	}

	/**
	 * Loads sponsorships indexed by sign request ID.
	 *
	 * Intended for callers that already have sign request identifiers and
	 * want to batch sponsorship lookups before enriching multiple signer
	 * collections.
	 *
	 * @param int[] $signRequestIds
	 *
	 * @return array<int, SignerSponsorship>
	 */
	public function loadSponsorshipMapBySignRequestIds(
		array $signRequestIds,
	): array {

		$signRequestIds = array_values(array_unique(array_filter(
			array_map('intval', $signRequestIds),
			static fn (int $id): bool => $id > 0,
		)));

		if ($signRequestIds === []) {
			return [];
		}

		return $this->mapper->findIndexedBySignRequestIds(
			$signRequestIds,
		);
	}

	/**
	 * Extract the sign request identifier from a signer payload.
	 *
	 * Supports both object- and array-based representations used throughout
	 * the application.
	 */
	private function readSignRequestId(mixed $signer): ?int
	{
		if (is_object($signer)) {
			return isset($signer->signRequestId)
				? (int) $signer->signRequestId
				: null;
		}

		if (is_array($signer)) {
			return isset($signer['signRequestId'])
				? (int) $signer['signRequestId']
				: null;
		}

		return null;
	}

	/**
	 * Applies sponsorship information to a signer payload.
	 *
	 * When no sponsorship exists, default self-sponsored values are applied so
	 * all returned signer payloads expose the same sponsorship properties.
	 */
	private function applySponsorship(
		mixed $signer,
		?SignerSponsorship $sponsorship,
	): mixed {

		$type = SponsorshipType::SELF->value;
		$sponsorUserId = null;
		$isSponsored = false;

		if ($sponsorship !== null) {
			$type = $sponsorship->getSponsorshipType();
			$sponsorUserId = $sponsorship->getSponsorUserId();
			$isSponsored =
				$sponsorship->getSponsorshipTypeEnum()
				=== SponsorshipType::REQUESTER;
		}

		if (is_object($signer)) {
			$signer->sponsorshipType = $type;
			$signer->sponsorUserId = $sponsorUserId;
			$signer->isSponsored = $isSponsored;

			return $signer;
		}

		if (is_array($signer)) {
			$signer['sponsorshipType'] = $type;
			$signer['sponsorUserId'] = $sponsorUserId;
			$signer['isSponsored'] = $isSponsored;
		}

		return $signer;
	}
}
