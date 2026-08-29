<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Memoized phone -> MNO resolution metadata.
 *
 * Row in the gopaperless_phone_mno_cache table. This is an optimization
 * layer only: it caches MNO IDENTITY (carrier), never the selected payment
 * provider/rail. If routing rules change, a cached carrier automatically
 * routes under the new rule because the rail is re-derived at runtime.
 *
 * @method void setPhoneE164Digits(string $phoneE164Digits)
 * @method string getPhoneE164Digits()
 *
 * @method void setRegion(?string $region)
 * @method ?string getRegion()
 *
 * @method void setCountry(?string $country)
 * @method ?string getCountry()
 *
 * @method void setMno(?string $mno)
 * @method ?string getMno()
 *
 * @method void setCarrierHint(?string $carrierHint)
 * @method ?string getCarrierHint()
 *
 * @method void setConfidence(string $confidence)
 * @method string getConfidence()
 *
 * @method void setResolverVersion(string $resolverVersion)
 * @method string getResolverVersion()
 *
 * @method void setResolvedAt(\DateTime $resolvedAt)
 * @method ?\DateTime getResolvedAt()
 *
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method ?\DateTime getCreatedAt()
 *
 * @method void setUpdatedAt(?\DateTime $updatedAt)
 * @method ?\DateTime getUpdatedAt()
 */
class PhoneMnoCache extends Entity {
	protected string $phoneE164Digits = '';
	protected ?string $region = null;
	protected ?string $country = null;
	protected ?string $mno = null;
	protected ?string $carrierHint = null;
	protected string $confidence = 'unknown';
	protected string $resolverVersion = '';
	protected ?\DateTime $resolvedAt = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('phoneE164Digits', 'string');
		$this->addType('region', 'string');
		$this->addType('country', 'string');
		$this->addType('mno', 'string');
		$this->addType('carrierHint', 'string');
		$this->addType('confidence', 'string');
		$this->addType('resolverVersion', 'string');
		$this->addType('resolvedAt', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
