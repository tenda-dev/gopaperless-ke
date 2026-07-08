<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Sponsorship\DTO;

final class SponsorshipReconciliationResultDTO
{
	/**
	 * @param IncomingSignerDTO[] $requiresReservation
	 * @param IncomingSignerDTO[] $requiresRelease
	 */
	public function __construct(
		private array $requiresReservation = [],
		private array $requiresRelease = [],
		private bool $hasChanges = false,
	) {
	}

	/**
	 * Signers requiring a new requester sponsorship.
	 *
	 * SELF -> REQUESTER
	 *
	 * @return IncomingSignerDTO[]
	 */
	public function getRequiresReservation(): array
	{
		return $this->requiresReservation;
	}

	/**
	 * Signers requiring sponsorship release.
	 *
	 * REQUESTER -> SELF
	 *
	 * @return IncomingSignerDTO[]
	 */
	public function getRequiresRelease(): array
	{
		return $this->requiresRelease;
	}

	public function hasChanges(): bool
	{
		return $this->hasChanges;
	}

	public function addRequiresReservation(
		IncomingSignerDTO $signer,
	): void {
		$this->requiresReservation[] = $signer;
		$this->hasChanges = true;
	}

	public function addRequiresRelease(
		IncomingSignerDTO $signer,
	): void {
		$this->requiresRelease[] = $signer;
		$this->hasChanges = true;
	}

	/**
	 * Number of new requester-sponsored reservations
	 * that must exist after synchronisation.
	 */
	public function getRequiredReservations(): int
	{
		return count($this->requiresReservation);
	}
}
