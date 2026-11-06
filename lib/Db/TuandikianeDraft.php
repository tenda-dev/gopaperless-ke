<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * An agreement Tuandikiane rendered, held by the service account until its
 * sender reviews and sends it (TuandikianeService).
 *
 * Each slot is one signer, in signing order:
 * {key: "s1", phone: "+2547...", sender: bool (at most one; none when the sender
 *  doesn't sign), named: bool (false: "Signer 1" until the slot is taken),
 *  signRequestId: int, uid: ?string (the account that took the slot),
 *  card: bool (WhatsApp card sent)}.
 *
 * @method void setDraftUuid(string $uuid)
 * @method string getDraftUuid()
 * @method void setReference(string $reference)
 * @method string getReference()
 * @method void setFileId(int $fileId)
 * @method int getFileId()
 * @method void setStatus(string $status)
 * @method string getStatus()
 * @method void setSlots(array $slots)
 * @method ?array getSlots()
 * @method void setCreatedAt(\DateTimeInterface $date)
 * @method \DateTimeInterface getCreatedAt()
 * @method void setExpiresAt(\DateTimeInterface $date)
 * @method \DateTimeInterface getExpiresAt()
 * @method void setSentAt(?\DateTimeInterface $date)
 * @method ?\DateTimeInterface getSentAt()
 */
class TuandikianeDraft extends Entity {
	public const STATUS_DRAFT = 'draft';
	public const STATUS_SENT = 'sent';

	protected string $draftUuid = '';
	protected string $reference = '';
	protected int $fileId = 0;
	protected string $status = self::STATUS_DRAFT;
	protected ?array $slots = [];
	protected ?\DateTimeInterface $createdAt = null;
	protected ?\DateTimeInterface $expiresAt = null;
	protected ?\DateTimeInterface $sentAt = null;

	public function __construct() {
		$this->addType('draftUuid', Types::STRING);
		$this->addType('reference', Types::STRING);
		$this->addType('fileId', Types::BIGINT);
		$this->addType('status', Types::STRING);
		$this->addType('slots', Types::JSON);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('expiresAt', Types::DATETIME);
		$this->addType('sentAt', Types::DATETIME);
	}
}
