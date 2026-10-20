<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents an anonymous public document-preparation workflow.
 *
 * Stores the workflow capability hash, temporary owner, lifecycle status,
 * document reference, requester preferences, and claim timestamps. The
 * associated LibreSign File record remains authoritative for document ownership.
 *
 * Only the SHA-256 hash of the capability token is stored.
 *
 * Lifecycle: active → claiming → claimed; revoked marks a terminated workflow.
 *
 * @method void setTokenHash(string $hash)
 * @method string getTokenHash()
 * @method void setOwnerUid(string $uid)
 * @method string getOwnerUid()
 * @method void setStatus(string $status)
 * @method string getStatus()
 * @method void setFileUuid(?string $uuid)
 * @method ?string getFileUuid()
 * @method void setRequesterEmail(?string $email)
 * @method ?string getRequesterEmail()
 * @method void setRequesterIsSigner(bool $isSigner)
 * @method bool getRequesterIsSigner()
 * @method void setClaimedByUid(?string $uid)
 * @method ?string getClaimedByUid()
 * @method void setCreatedAt(\DateTimeInterface|string $date)
 * @method \DateTimeInterface getCreatedAt()
 * @method void setExpiresAt(\DateTimeInterface|string $date)
 * @method \DateTimeInterface getExpiresAt()
 * @method void setClaimStartedAt(?\DateTimeInterface $date)
 * @method ?\DateTimeInterface getClaimStartedAt()
 * @method void setClaimedAt(?\DateTimeInterface $date)
 * @method ?\DateTimeInterface getClaimedAt()
 * @method void setUpdatedAt(?\DateTimeInterface $date)
 * @method ?\DateTimeInterface getUpdatedAt()
 */
class PublicWorkflow extends Entity {
	public const STATUS_ACTIVE = 'active';
	public const STATUS_CLAIMING = 'claiming';
	public const STATUS_CLAIMED = 'claimed';
	public const STATUS_REVOKED = 'revoked';

	protected string $tokenHash = '';
	protected string $ownerUid = '';
	protected string $status = self::STATUS_ACTIVE;
	protected ?string $fileUuid = null;
	protected ?string $requesterEmail = null;
	protected bool $requesterIsSigner = false;
	protected ?string $claimedByUid = null;
	protected ?\DateTimeInterface $createdAt = null;
	protected ?\DateTimeInterface $expiresAt = null;
	protected ?\DateTimeInterface $claimStartedAt = null;
	protected ?\DateTimeInterface $claimedAt = null;
	protected ?\DateTimeInterface $updatedAt = null;

	public function __construct() {
		$this->addType('tokenHash', 'string');
		$this->addType('ownerUid', 'string');
		$this->addType('status', 'string');
		$this->addType('fileUuid', 'string');
		$this->addType('requesterEmail', 'string');
		$this->addType('requesterIsSigner', 'boolean');
		$this->addType('claimedByUid', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('expiresAt', 'datetime');
		$this->addType('claimStartedAt', 'datetime');
		$this->addType('claimedAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
