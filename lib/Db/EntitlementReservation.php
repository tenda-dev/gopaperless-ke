<?php

declare(strict_types=1);

namespace OCA\Libresign\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents a reservation of entitlement capacity.
 *
 * Example:
 * - User purchases 10 credits
 * - Workflow reserves 3 signers
 * - remaining_uses = 10
 * - reserved_uses = 3
 *
 * Reservation rows provide the source of truth
 * while Entitlement.reserved_uses acts as a fast aggregate.
 *
 * @method void setEntitlementId(int $id)
 * @method int getEntitlementId()
 *
 * @method void setFileId(int $fileId)
 * @method int getFileId()
 *
 * @method void setSignRequestId(int $signRequestId)
 * @method int getSignRequestId()
 *
 * @method void setSponsorUserId(string $userId)
 * @method string getSponsorUserId()
 *
 * @method void setQuantity(int $quantity)
 * @method int getQuantity()
 *
 * @method void setCreatedAt(\DateTimeInterface|string $createdAt)
 * @method \DateTimeInterface|string getCreatedAt()
 *
 * @method void setReleasedAt(\DateTimeInterface|string $releasedAt)
 * @method \DateTimeInterface|string getReleasedAt()
 */
class EntitlementReservation extends Entity
{
	protected ?int $entitlementId = null;

	protected ?int $fileId = null;

	protected int $signRequestId = 0;

	protected ?string $sponsorUserId = null;

	protected int $quantity = 1;

	protected ?\DateTimeInterface $createdAt = null;

	protected \DateTimeInterface|string|null $releasedAt = null;

	/**
	 * @throws \Exception
	 */
	public function __construct()
	{
		$this->setCreatedAt($this->now());

		// integers
		$this->addType('entitlementId', 'integer');
		$this->addType('fileId', 'integer');
		$this->addType('signRequestId', 'integer');
		$this->addType('quantity', 'integer');

		// datetime
		$this->addType('createdAt', 'datetime');
		$this->addType('releasedAt', 'datetime');

		// strings
		$this->addType('sponsorUserId', 'string');
	}

	public function validate(): void
	{
		if ($this->entitlementId === null) {
			throw new \InvalidArgumentException(
				'entitlementId is required'
			);
		}

		if ($this->fileId === null) {
			throw new \InvalidArgumentException(
				'fileId is required'
			);
		}

		if ($this->signRequestId <= 0) {
			throw new \InvalidArgumentException(
				'signRequestId is required'
			);
		}

		if ($this->sponsorUserId === null || $this->sponsorUserId === '') {
			throw new \InvalidArgumentException(
				'sponsorUserId is required'
			);
		}

		if ($this->quantity <= 0) {
			throw new \InvalidArgumentException(
				'quantity must be greater than zero'
			);
		}
	}

	private function now(): \DateTimeImmutable
	{
		return new \DateTimeImmutable(
			'now',
			new \DateTimeZone('UTC'),
		);
	}
}
