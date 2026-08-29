<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Db\PhoneMnoOverride;
use OCA\Libresign\Db\PhoneMnoOverrideMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;

/**
 * Admin-facing management of phone-number exceptions.
 *
 * Every exception created here means the SAME thing:
 *   phone -> Safaricom -> (existing MnoRoutingRegistry) -> Daraja/STK.
 *
 * The service only writes MNO identity ('safaricom') into the override
 * table. It contains NO DPO/Daraja/rail logic: routing stays entirely with
 * PhoneMnoResolver -> MnoRoutingRegistry. The provider/rail is intentionally
 * absent from both the table and this service.
 */
class PhoneOverrideAdminService {
	/** Fixed MNO identity for this admin workflow. Matches MnoRoutingRegistry KE 'safaricom' -> Daraja. */
	public const MNO_SAFARICOM = 'safaricom';

	private const DEFAULT_NOTE = 'Treated as Safaricom via admin Phone Number Exceptions.';

	/** Sanity bounds on the normalized digits (E.164 max is 15). Not an isValidNumber gate. */
	private const MIN_DIGITS = 7;
	private const MAX_DIGITS = 15;

	public function __construct(
		private PhoneMnoOverrideMapper $overrideMapper,
		private PaymentDateTimeHelper $dateTimeHelper,
	) {
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function listAll(): array {
		return array_map(
			fn (PhoneMnoOverride $o): array => $this->serialize($o),
			$this->overrideMapper->findAll(),
		);
	}

	/**
	 * Create (or reactivate) a Safaricom exception for a phone number.
	 *
	 * Normalization is deterministic and library-free (same key the resolver
	 * and payment cache use), so it does NOT depend on libphonenumber validity
	 * or carrier detection — that is the whole point: it must be able to store
	 * a number libphonenumber would reject.
	 *
	 * @return array{status: 'created'|'reactivated'|'duplicate', override: array<string, mixed>}
	 * @throws \InvalidArgumentException on empty/implausible input
	 */
	public function createSafaricomException(string $rawPhone, ?string $createdBy = null): array {
		$key = $this->normalizeOrThrow($rawPhone);
		$now = $this->now();

		$existing = $this->overrideMapper->findByPhone($key);
		if ($existing !== null) {
			if ($existing->getActive()) {
				return ['status' => 'duplicate', 'override' => $this->serialize($existing)];
			}

			$existing->setActive(true);
			$existing->setUpdatedAt($now);
			if ($createdBy !== null && $createdBy !== '') {
				$existing->setCreatedBy($createdBy);
			}

			return [
				'status' => 'reactivated',
				'override' => $this->serialize($this->overrideMapper->update($existing)),
			];
		}

		$entity = new PhoneMnoOverride();
		$entity->setPhoneE164Digits($key);
		$entity->setMno(self::MNO_SAFARICOM);
		$entity->setActive(true);
		$entity->setNote(self::DEFAULT_NOTE);
		if ($createdBy !== null && $createdBy !== '') {
			$entity->setCreatedBy($createdBy);
		}
		$entity->setCreatedAt($now);
		$entity->setUpdatedAt($now);

		try {
			$saved = $this->overrideMapper->insert($entity);
		} catch (DbException $e) {
			// Unique-constraint race: surface as a clean duplicate, never a raw DB error.
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				$again = $this->overrideMapper->findByPhone($key);
				if ($again !== null) {
					return ['status' => 'duplicate', 'override' => $this->serialize($again)];
				}
			}
			throw $e;
		}

		return ['status' => 'created', 'override' => $this->serialize($saved)];
	}

	/**
	 * Update an existing exception: change the phone number and/or active state.
	 *
	 * @return array{status: 'updated'|'duplicate', override: array<string, mixed>}
	 * @throws DoesNotExistException when the id does not exist (-> 404)
	 * @throws \InvalidArgumentException on implausible phone input (-> 400)
	 */
	public function update(int $id, ?string $rawPhone = null, ?bool $active = null): array {
		$entity = $this->overrideMapper->findById($id);

		if ($rawPhone !== null) {
			$key = $this->normalizeOrThrow($rawPhone);
			if ($key !== $entity->getPhoneE164Digits()) {
				$other = $this->overrideMapper->findByPhone($key);
				if ($other !== null && $other->getId() !== $id) {
					return ['status' => 'duplicate', 'override' => $this->serialize($other)];
				}
				$entity->setPhoneE164Digits($key);
			}
		}

		if ($active !== null) {
			$entity->setActive($active);
		}

		$entity->setUpdatedAt($this->now());

		return ['status' => 'updated', 'override' => $this->serialize($this->overrideMapper->update($entity))];
	}

	/**
	 * Permanently remove an exception.
	 *
	 * @throws DoesNotExistException when the id does not exist (-> 404)
	 */
	public function delete(int $id): void {
		$entity = $this->overrideMapper->findById($id);
		$this->overrideMapper->delete($entity);
	}

	private function normalizeOrThrow(string $rawPhone): string {
		$key = PhoneMnoResolver::normalizePhoneKey($rawPhone);
		if ($key === null) {
			throw new \InvalidArgumentException('Enter a phone number.');
		}

		$digits = ltrim($key, '+');
		$len = strlen($digits);
		if ($len < self::MIN_DIGITS || $len > self::MAX_DIGITS) {
			throw new \InvalidArgumentException(
				'Enter a valid phone number in international format, for example +2547XXXXXXXX.'
			);
		}

		return $key;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function serialize(PhoneMnoOverride $o): array {
		return [
			'id' => $o->getId(),
			'phone' => $o->getPhoneE164Digits(),
			'mno' => $o->getMno(),
			'active' => $o->getActive(),
			'note' => $o->getNote(),
			'createdBy' => $o->getCreatedBy(),
			'createdAt' => $this->formatDate($o->getCreatedAt()),
			'updatedAt' => $this->formatDate($o->getUpdatedAt()),
		];
	}

	private function formatDate(?\DateTimeInterface $date): ?string {
		return $date?->format(\DateTimeInterface::ATOM);
	}

	private function now(): \DateTime {
		return \DateTime::createFromInterface($this->dateTimeHelper->nowImmutable());
	}
}
