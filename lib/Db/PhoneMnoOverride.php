<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Authoritative, human-curated phone -> MNO override.
 *
 * Row in the gopaperless_phone_overrides table. The MNO identifies the
 * network while provider explicitly controls the payment rail for the
 * exceptional number.
 *
 * @method void setPhoneE164Digits(string $phoneE164Digits)
 * @method string getPhoneE164Digits()
 *
 * @method void setProvider(string $provider)
 * @method string getProvider()
 *
 * @method void setProviderMnoKey(?string $providerMnoKey)
 * @method ?string getProviderMnoKey()
 *
 * @method void setMno(string $mno)
 * @method string getMno()
 *
 * @method void setActive(bool $active)
 * @method bool getActive()
 *
 * @method void setNote(?string $note)
 * @method ?string getNote()
 *
 * @method void setCreatedBy(?string $createdBy)
 * @method ?string getCreatedBy()
 *
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method ?\DateTime getCreatedAt()
 *
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 * @method ?\DateTime getUpdatedAt()
 */
class PhoneMnoOverride extends Entity {
	protected string $phoneE164Digits = '';
	protected string $provider = '';
	protected string $mno = '';
	protected ?string $providerMnoKey = null;
	protected bool $active = true;
	protected ?string $note = null;
	protected ?string $createdBy = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('phoneE164Digits', 'string');
		$this->addType('provider', 'string');
		$this->addType('providerMnoKey', 'string');
		$this->addType('mno', 'string');
		$this->addType('active', 'boolean');
		$this->addType('note', 'string');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
