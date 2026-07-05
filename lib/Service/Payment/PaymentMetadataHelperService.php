<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Service\DateTimeService;
use OCA\Libresign\Service\Payment\DTO\PaymentMetadataDTO;
use OCA\Libresign\Service\Payment\DTO\ProviderPayloadDTO;

/**
 * Reads and enriches payment metadata DTOs.
 *
 * Operates only on PaymentMetadataDTO / ProviderPayloadDTO; takes DTOs,
 * returns DTOs. It never touches a Payment entity, the mapper, or the DB;
 * persistence stays with the lifecycle/service layer. This keeps metadata
 * transformation a pure, testable concern separate from state writes.
 */
class PaymentMetadataHelperService
{
	public function __construct(
		private DateTimeService $dateTimeService,
	) {
	}

	/**
	 * Append provider error detail onto metadata, stamping updatedAt.
	 * Merges into any existing providerError rather than replacing it.
	 * Caller may supply the timestamp to keep a batch of writes consistent;
	 * otherwise "now" is used.
	 */
	public function appendProviderError(
		PaymentMetadataDTO $meta,
		array $error,
		?\DateTimeImmutable $nowImmutable = null,
	): PaymentMetadataDTO {
		return $meta->with(
			updatedAt: $nowImmutable ?? $this->dateTimeService->nowImmutable(),
			providerError: [
				...($meta->providerError ?? []),
				...$error,
			],
		);
	}

	/**
	 * Return the metadata's provider payload, or an empty payload if none
	 * is set, so callers can always chase a with*() call without a null
	 * check.
	 */
	public function getProviderPayload(
		PaymentMetadataDTO $meta,
	): ProviderPayloadDTO {
		return $meta->providerPayload
			?? new ProviderPayloadDTO();
	}
}
