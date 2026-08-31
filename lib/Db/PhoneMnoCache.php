<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Db;

use OCA\Libresign\Enum\ResolutionConfidence;
use OCP\AppFramework\Db\Entity;

/**
 * Memoized phone -> MNO resolution and verified payment routing metadata.
 *
 * Row in the gopaperless_phone_mno_cache table. This is an optimization
 * layer that caches canonical MNO identity and, when verified, the payment
 * provider successfully used for that phone number together with the
 * provider-native MNO execution identifier.
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
 * @method void setProvider(?string $provider)
 * @method ?string getProvider()
 *
 * @method void setProviderMnoKey(?string $providerMnoKey)
 * @method ?string getProviderMnoKey()
 *
 * @method void setVerified(bool $verified)
 * @method bool getVerified()
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
	protected ?string $provider = null;
	protected ?string $providerMnoKey = null;
	protected ?string $mno = null;
	protected ?string $carrierHint = null;
	protected string $confidence = ResolutionConfidence::UNKNOWN->value;
	protected bool $verified = false;
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
		$this->addType('provider', 'string');
		$this->addType('providerMnoKey', 'string');
		$this->addType('carrierHint', 'string');
		$this->addType('confidence', 'string');
		$this->addType('verified', 'boolean');
		$this->addType('resolverVersion', 'string');
		$this->addType('resolvedAt', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
