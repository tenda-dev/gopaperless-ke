<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Db\PhoneMnoOverride;
use OCA\Libresign\Db\PhoneMnoOverrideMapper;
use OCA\Libresign\Enum\PaymentProvider;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use Psr\Log\LoggerInterface;

/**
 * Admin-facing management of phone-number payment routing exceptions.
 *
 * Each exception explicitly defines the MNO identity and payment provider
 * that should be used for the phone number.
 */
class PhoneOverrideAdminService {
	private const MIN_DIGITS = 7;
	private const MAX_DIGITS = 15;

	private const DEFAULT_NOTE = 'Payment routing override configured by administrator.';

	public function __construct(
		private PhoneMnoOverrideMapper $overrideMapper,
		private PaymentDateTimeHelper $dateTimeHelper,
		private MnoRoutingRegistry $mnoRoutingRegistry,
		private PhoneResolutionService $phoneResolutionService,
		private MnoSuggestionValidatorService $mnoSuggestionValidatorService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function listAll(): array {
		return array_map(
			fn (PhoneMnoOverride $override): array => $this->serialize($override),
			$this->overrideMapper->findAll(),
		);
	}

	/**
	 * Create (or reactivate) a payment routing exception for a phone number.
	 *
	 * Normalization is deterministic and does not depend on MNO detection or
	 * libphonenumber validity.
	 *
	 * @return array{status: 'created'|'reactivated'|'duplicate', override: array<string, mixed>}
	 *
	 * @throws \InvalidArgumentException on invalid MNO, provider, or phone input
	 */
	public function createException(
		string $rawPhone,
		string $mno,
		string $provider,
		?string $createdBy = null,
	): array {
		$mno = $this->normalizeMnoOrThrow($mno);
		$provider = $this->normalizeProviderOrThrow($provider);
		$key = $this->normalizeOrThrow($rawPhone);
		$region = $this->regionFor($rawPhone);
		$this->assertSupportedCombination($mno, $provider, $region);

		$providerMnoKey = $this->providerMnoKeyFor(
			$mno,
			$provider,
			$region,
		);

		$now = $this->now();

		$existing = $this->overrideMapper->findByPhone($key);
		if ($existing !== null) {
			if ($existing->getActive()) {
				return [
					'status' => 'duplicate',
					'override' => $this->serialize($existing),
				];
			}

			$existing->setMno($mno);
			$existing->setProvider($provider);
			$existing->setProviderMnoKey($providerMnoKey);
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
		$entity->setMno($mno);
		$entity->setProvider($provider);
		$entity->setProviderMnoKey($providerMnoKey);
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
			// Unique-constraint race: surface as a clean duplicate,
			// never a raw database error.
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				$again = $this->overrideMapper->findByPhone($key);

				if ($again !== null) {
					return [
						'status' => 'duplicate',
						'override' => $this->serialize($again),
					];
				}
			}

			throw $e;
		}

		return [
			'status' => 'created',
			'override' => $this->serialize($saved),
		];
	}

	/**
	 * Update an existing exception.
	 *
	 * @return array{status: 'updated'|'duplicate', override: array<string, mixed>}
	 *
	 * @throws DoesNotExistException when the id does not exist
	 * @throws \InvalidArgumentException on invalid input
	 */
	public function update(
		int $id,
		?string $rawPhone = null,
		?string $mno = null,
		?string $provider = null,
		?bool $active = null,
	): array {
		$entity = $this->overrideMapper->findById($id);

		if ($rawPhone !== null) {
			$key = $this->normalizeOrThrow($rawPhone);

			if ($key !== $entity->getPhoneE164Digits()) {
				$other = $this->overrideMapper->findByPhone($key);

				if ($other !== null && $other->getId() !== $id) {
					return [
						'status' => 'duplicate',
						'override' => $this->serialize($other),
					];
				}

				$entity->setPhoneE164Digits($key);
			}
		}

		if ($mno !== null) {
			$entity->setMno($this->normalizeMnoOrThrow($mno));
		}

		if ($provider !== null) {
			$entity->setProvider($this->normalizeProviderOrThrow($provider));
		}

		if ($active !== null) {
			$entity->setActive($active);
		}

		// Re-validate the combination when a routing input changed (skip pure
		// active toggles so deactivation is never blocked).
		if ($rawPhone !== null || $mno !== null || $provider !== null) {
			$region = $this->regionFor($entity->getPhoneE164Digits());

			$this->logger->debug('[PhoneOverrideAdmin] Validating override route', [
				'mno' => $entity->getMno(),
				'provider' => $entity->getProvider(),
				'region' => $region,
				'supportsRoute' => $this->mnoRoutingRegistry->supportsRoute(
					$region,
					$entity->getMno(),
					PaymentProvider::from($entity->getProvider()),
				),
			]);

			$this->assertSupportedCombination(
				$entity->getMno(),
				$entity->getProvider(),
				$region,
			);

			$entity->setProviderMnoKey(
				$this->providerMnoKeyFor(
					$entity->getMno(),
					$entity->getProvider(),
					$region,
				)
			);
		}

		$entity->setUpdatedAt($this->now());

		return [
			'status' => 'updated',
			'override' => $this->serialize($this->overrideMapper->update($entity)),
		];
	}

	/**
	 * Permanently remove an exception.
	 *
	 * @throws DoesNotExistException when the id does not exist
	 */
	public function delete(int $id): void {
		$entity = $this->overrideMapper->findById($id);
		$this->overrideMapper->delete($entity);
	}

	private function normalizeOrThrow(string $rawPhone): string {
		$parsed = $this->phoneResolutionService->parseLenient($rawPhone, 'KE');

		if ($parsed === null || $parsed['e164'] === null) {
			throw new \InvalidArgumentException('Enter a valid phone number.');
		}

		$key = $parsed['e164'];

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

	private function assertSupportedCombination(string $mno, string $provider, ?string $region): void {
		if (!$this->mnoRoutingRegistry->supportsRoute($region, $mno, PaymentProvider::from($provider))) {
			throw new \InvalidArgumentException(sprintf(
				'The %s network cannot be routed through %s in this region.',
				$mno,
				$provider,
			));
		}
	}

	private function regionFor(string $rawPhone): ?string {
		$parsed = $this->phoneResolutionService->parseLenient($rawPhone, 'KE');

		return $parsed['region'] ?? null;
	}

	private function normalizeMnoOrThrow(string $mno): string {
		$mno = strtolower(trim($mno));

		if ($mno === '') {
			throw new \InvalidArgumentException('Select a mobile network.');
		}

		/*
		 * MNO validation is intentionally kept separate from provider
		 * validation. The canonical MNO values should come from the
		 * application's supported MNO/routing definitions.
		 */
		if (!$this->mnoRoutingRegistry->supportsMno($mno)) {
			throw new \InvalidArgumentException('Select a supported mobile network.');
		}

		return $mno;
	}

	private function providerMnoKeyFor(
		string $mno,
		string $provider,
		?string $region,
	): ?string {
		$paymentProvider = PaymentProvider::from($provider);

		if ($paymentProvider !== PaymentProvider::DPO) {
			return null;
		}

		$providerMnoKey = $this->mnoSuggestionValidatorService
			->providerMnoKeyForCanonical($mno, $region);

		if ($providerMnoKey === null) {
			throw new \InvalidArgumentException(
				'No provider MNO key is configured for this network in this region.'
			);
		}

		return $providerMnoKey;
	}

	private function normalizeProviderOrThrow(string $provider): string {
		$provider = strtolower(trim($provider));

		try {
			$paymentProvider = PaymentProvider::from($provider);
		} catch (\ValueError) {
			throw new \InvalidArgumentException('Select a supported payment provider.');
		}

		return $paymentProvider->value;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function serialize(PhoneMnoOverride $override): array {
		return [
			'id' => $override->getId(),
			'phone' => $override->getPhoneE164Digits(),
			'mno' => $override->getMno(),
			'provider' => $override->getProvider(),
			'providerMnoKey' => $override->getProviderMnoKey(),
			'active' => $override->getActive(),
			'note' => $override->getNote(),
			'createdBy' => $override->getCreatedBy(),
			'createdAt' => $this->formatDate($override->getCreatedAt()),
			'updatedAt' => $this->formatDate($override->getUpdatedAt()),
		];
	}

	private function formatDate(?\DateTimeInterface $date): ?string {
		return $date?->format(\DateTimeInterface::ATOM);
	}

	private function now(): \DateTime {
		return \DateTime::createFromInterface($this->dateTimeHelper->nowImmutable());
	}
}
