<?php

declare(strict_types=1);

namespace OCA\Libresign\Db;

use OCA\Libresign\Enum\EntitlementType;
use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the gopaperless_entitlements table.
 *
 * Key Concept:
 * - Entitlement = what a user is allowed to do AFTER payment
 *
 * Example:
 * - Product: SIGN_DOCUMENT
 * - Entitlement: remaining_uses = 1
 *
 * IMPORTANT:
 * - Created ONLY after successful payment
 * - Checked BEFORE allowing an action (e.g. signing)
 *
 * Future-ready:
 * - remaining_uses → supports credits
 * - expires_at → supports subscriptions
 *
 * @method void setUserId(string $userId)
 * @method string getUserId()
 *
 * @method void setProductCode(string $code)
 * @method string getProductCode()
 *
 * @method void setRemainingUses(?int $uses)
 * @method ?int getRemainingUses()
 *
 * @method void setReservedUses(?int $uses)
 * @method ?int getReservedUses()
 *
 * @method void setExpiresAt(?\DateTimeInterface | string $date)
 * @method ?\DateTimeInterface getExpiresAt()
 *
 * @method void setCreatedAt(\DateTimeInterface | string $date)
 * @method \DateTimeInterface getCreatedAt()
 *
 * @method void setType(string $type)
 * @method string getType()
 */
class Entitlement extends Entity
{

	/**
	 * CRITICAL:
	 * Initialise ALL typed properties to avoid hydration errors
	 */

	protected string $userId = '';
	protected string $productCode = '';

	/**
	 * NULL = unlimited (future use)
	 */
	protected ?int $remainingUses = null;

	/**
	 * NULL = no expiry
	 */
	protected ?\DateTimeInterface $expiresAt = null;

	protected \DateTimeInterface $createdAt;


	protected ?int $reservedUses = 0;

	protected string $type = EntitlementType::PAY_AS_YOU_GO->value;

	/**
	 * @throws \Exception
	 */
	public function __construct()
	{

		// Ensure createdAt is ALWAYS initialized
		$this->createdAt = new \DateTimeImmutable(
			'now',
			new \DateTimeZone('UTC')
		);

		/**
		 * Type mappings for Nextcloud hydration
		 */

		// integers
		$this->addType('remainingUses', 'integer');
		$this->addType('reservedUses', 'integer');

		// datetime
		$this->addType('createdAt', 'datetime');
		$this->addType('expiresAt', 'datetime');

		// strings
		$this->addType('userId', 'string');
		$this->addType('productCode', 'string');
		$this->addType('type', 'string');
	}

	/**
	 * Validate entity before insert/update
	 */
	public function validate(): void
	{

		if ($this->userId === '') {
			throw new \InvalidArgumentException('userId is required');
		}

		if ($this->productCode === '') {
			throw new \InvalidArgumentException('productCode is required');
		}

		// remainingUses can be null (unlimited)
		if ($this->remainingUses !== null && $this->remainingUses < 0) {
			throw new \InvalidArgumentException('remainingUses cannot be negative');
		}

		if ($this->reservedUses !== null && $this->reservedUses < 0) {
			throw new \InvalidArgumentException(
				'reservedUses cannot be negative'
			);
		}
	}

	/**
	 * Check if entitlement is expired
	 */
	public function isExpired(): bool
	{
		return $this->expiresAt !== null && $this->expiresAt < new \DateTime();
	}

	/**
	 * Check if entitlement can be used
	 */
	public function canUse(): bool
	{

		// Expiry check
		if ($this->isExpired()) {
			return false;
		}

		// Unlimited usage
		if ($this->remainingUses === null) {
			return true;
		}

		return $this->getAvailableUses() > 0;
	}

	/**
	 * Consume one usage
	 *
	 * NOTE:
	 * - Does NOT persist (service must handle update)
	 */
	public function consume(int $quantity = 1): void
	{

		if ($this->remainingUses === null) {
			// unlimited → nothing to decrement
			return;
		}

		if ($this->remainingUses <= 0) {
			throw new \RuntimeException('No remaining uses');
		}

		if ($quantity <= 0) {
			throw new \InvalidArgumentException(
				'Quantity must be greater than zero'
			);
		}

		if ($quantity > $this->remainingUses) {
			throw new \RuntimeException(
				sprintf(
					'Cannot consume %d uses; only %d remaining',
					$quantity,
					$this->remainingUses
				)
			);
		}

		$this->setRemainingUses($this->remainingUses - $quantity);
	}

	public function getRemainingUses(): ?int
	{
		return $this->remainingUses;
	}

	public function getAvailableUses(): ?int
	{
		if ($this->remainingUses === null) {
			return null;
		}

		return $this->remainingUses
			- ($this->reservedUses ?? 0);
	}

	public function setEntitlementType(
		EntitlementType $type,
	): void {
		$this->setType($type->value);
	}

	public function getEntitlementType(): EntitlementType
	{
		return EntitlementType::from($this->getType());
	}
}
