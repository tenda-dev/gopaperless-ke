<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Db;

use DateTimeInterface;
use OCP\AppFramework\Db\Entity;

/**
 * Represents a row in the gopaperless_extended_accounts table.
 *
 * The absence of a row is a valid business state used to preserve
 * backwards compatibility for grandfathered accounts.
 *
 * NOTE:
 * Nextcloud Entity uses reflection and WILL try to access ALL properties.
 * Therefore, ALL typed properties MUST be initialized.
 *
 * @method void setCreatedAt(\DateTimeInterface|string $date)
 * @method \DateTimeInterface|string getCreatedAt()
 *
 * @method void setUserId(string $userId)
 * @method string getUserId()
 *
 * @method void setIdNumber(?string $idNumber)
 * @method ?string getIdNumber()
 *
 * @method void setPaidCertificate(bool $paid)
 * @method bool getPaidCertificate()
 *
 * @method void setCertPaidAt(?\DateTimeInterface|string $date)
 * @method ?\DateTimeInterface|string getCertPaidAt()
 *
 * @method void setValidUntil(?\DateTimeInterface|string $date)
 * @method ?\DateTimeInterface|string getValidUntil()
 */
class ExtendedAccount extends Entity {

	protected DateTimeInterface|string $createdAt = '';

	protected string $userId = '';

	protected ?string $idNumber = null;

	protected bool $paidCertificate = false;

	protected DateTimeInterface|string|null $certPaidAt = null;

	protected DateTimeInterface|string|null $validUntil = null;

	/**
	 * @throws \Exception
	 */
	public function __construct() {
		$this->setCreatedAt($this->nowImmutable());

		$this->addType('createdAt', 'datetime');
		$this->addType('userId', 'string');
		$this->addType('idNumber', 'string');
		$this->addType('paidCertificate', 'boolean');
		$this->addType('certPaidAt', 'datetime');
		$this->addType('validUntil', 'datetime');
	}

	private function nowImmutable(): \DateTimeImmutable {
		return new \DateTimeImmutable(
			'now',
			new \DateTimeZone('UTC'),
		);
	}

	private function now(): string {
		return $this->nowImmutable()->format(DATE_ATOM);
	}

	public function getCreatedAtImmutable(): ?\DateTimeImmutable {
		return $this->asDateTime($this->createdAt);
	}

	public function getCertPaidAtImmutable(): ?\DateTimeImmutable {
		return $this->asDateTime($this->certPaidAt);
	}

	public function getValidUntilImmutable(): ?\DateTimeImmutable {
		return $this->asDateTime($this->validUntil);
	}

	/**
	 * Whether certificate payment is currently required before
	 * certificate-dependent functionality may be used.
	 */
	public function isCertificateRequired(): bool {
		return !$this->isCertificateValid();
	}

	/**
	 * Determines whether the account currently has valid certificate access.
	 *
	 * A certificate is considered valid when:
	 * - certificate access has been granted; and
	 * - no business expiry exists, or the expiry is in the future.
	 */
	public function isCertificateValid(): bool {
		if (!$this->paidCertificate) {
			return false;
		}

		$validUntil = $this->getValidUntilImmutable();

		if ($validUntil === null) {
			return true;
		}

		return $validUntil > $this->nowImmutable();
	}

	/**
	 * Marks certificate access as obtained through a successful payment.
	 *
	 * Records the payment timestamp, defaulting to the current UTC time when
	 * none is supplied. The business validity window (validUntil) is managed
	 * separately by the service using the configured certificate duration.
	 */
	public function markCertificatePaid(
		\DateTimeInterface|string|null $paidAt = null,
	): self {

		$paidAt ??= $this->now();

		$this->setPaidCertificate(true);
		$this->setCertPaidAt($paidAt);

		return $this;
	}

	/**
	 * Grants certificate access without recording a payment.
	 *
	 * Intended for administrative or complimentary access. The supplied
	 * validity window is applied directly and is not calculated here.
	 */
	public function grantCertificateAccess(
		\DateTimeInterface|string|null $validUntil = null,
	): self {

		$this->setPaidCertificate(true);
		$this->setCertPaidAt(null);
		$this->setValidUntil($validUntil);

		return $this;
	}

	/**
	 * Renews certificate access following a successful payment.
	 *
	 * Records the payment timestamp and extends the certificate
	 * validity window.
	 */
	public function renewCertificate(
		\DateTimeInterface|string $validUntil,
		\DateTimeInterface|string|null $paidAt = null,
	): self {

		$this->markCertificatePaid($paidAt);

		$this->setValidUntil($validUntil);

		return $this;
	}

	/**
	 * Safely convert persisted date values into immutable instances.
	 */
	private function asDateTime(
		\DateTimeInterface|string|null $value,
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
}
