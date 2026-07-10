<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship\DTO;

final class SponsorshipReconciliationResultDTO
{
	/**
	 * @param IncomingSignerDTO[] $queuedReservations
	 * @param IncomingSignerDTO[] $queuedReleases
	 */
	public function __construct(
		private array $queuedReservations = [],
		private array $queuedReleases = [],
		private bool $hasChanges = false,
	) {
	}

	/**
	 * Signers that require a requester sponsorship
	 * to be created during synchronisation.
	 *
	 * SELF -> REQUESTER
	 *
	 * @return IncomingSignerDTO[]
	 */
	public function getQueuedReservations(): array
	{
		return $this->queuedReservations;
	}

	/**
	 * Signers whose requester sponsorship
	 * should be released during synchronisation.
	 *
	 * REQUESTER -> SELF
	 *
	 * @return IncomingSignerDTO[]
	 */
	public function getQueuedReleases(): array
	{
		return $this->queuedReleases;
	}

	public function hasChanges(): bool
	{
		return $this->hasChanges;
	}

	public function queueReservation(
		IncomingSignerDTO $signer,
	): void {
		$this->queuedReservations[] = $signer;
		$this->hasChanges = true;
	}

	public function queueRelease(
		IncomingSignerDTO $signer,
	): void {
		$this->queuedReleases[] = $signer;
		$this->hasChanges = true;
	}

	/**
	 * Number of requester sponsorships
	 * that must be reserved.
	 */
	public function getQueuedReservationCount(): int
	{
		return count($this->queuedReservations);
	}

	/**
	 * Number of requester sponsorships
	 * that must be released.
	 */
	public function getQueuedReleaseCount(): int
	{
		return count($this->queuedReleases);
	}
}
