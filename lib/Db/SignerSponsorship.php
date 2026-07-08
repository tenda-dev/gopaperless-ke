<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Db;

use OCA\Libresign\Enum\SponsorshipType;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
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
 * @method void setSponsorshipType(string $type)
 * @method string getSponsorshipType()
 *
 * @method void setCreatedAt(\DateTimeInterface $createdAt)
 * @method \DateTimeInterface getCreatedAt()
 *
 * @method void setUpdatedAt(\DateTimeInterface|string $updatedAt)
 * @method \DateTimeInterface|string getUpdatedAt()
 */
class SignerSponsorship extends Entity
{

	protected ?int $fileId = null;

	protected ?int $signRequestId = null;

	protected ?string $sponsorUserId = null;

	protected string $sponsorshipType = SponsorshipType::SELF->value;

	protected ?\DateTimeInterface $createdAt = null;
	protected ?\DateTimeInterface $updatedAt = null;

	public function __construct()
	{
		/**
		 * Ensure createdAt is tracked by Entity
		 * so ORM persists it during insert.
		 * Ensure DateTime is ALWAYS initialised
		 */
		$this->setCreatedAt($this->now());
		$this->setUpdatedAt($this->now());

		$this->addType('fileId', Types::INTEGER);

		$this->addType('signRequestId', Types::INTEGER);

		$this->addType('sponsorUserId', Types::STRING);

		$this->addType('sponsorshipType', Types::STRING);

		$this->addType('createdAt', Types::DATETIME);

		$this->addType('updatedAt', Types::DATETIME);
	}

	public function getSponsorshipTypeEnum(): SponsorshipType
	{
		return SponsorshipType::from($this->sponsorshipType);
	}

	public function setSponsorshipTypeEnum(
		SponsorshipType $type
	): void {
		$this->setSponsorshipType($type->value);
	}

	public function getCreatedAtImmutable(): ?\DateTimeImmutable
	{
		return $this->asDateTime($this->createdAt);
	}

	public function getUpdatedAtImmutable(): ?\DateTimeImmutable
	{
		return $this->asDateTime($this->updatedAt);
	}

	public function validate(): void
	{
		if ($this->fileId === null) {
			throw new \InvalidArgumentException('fileId is required');
		}

		if ($this->signRequestId === null) {
			throw new \InvalidArgumentException('signRequestId is required');
		}

		if ($this->sponsorUserId === null || $this->sponsorUserId === '') {
			throw new \InvalidArgumentException('sponsorUserId is required');
		}
	}

	private function asDateTime(
		\DateTimeInterface|string|null $value
	): ?\DateTimeImmutable {

		if ($value === null) {
			return null;
		}

		if ($value instanceof \DateTimeInterface) {
			return \DateTimeImmutable::createFromInterface($value);
		}

		try {
			return new \DateTimeImmutable($value);
		} catch (\Exception) {
			return null;
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
