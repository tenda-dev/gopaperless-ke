<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment\Interfaces;

use OCA\Libresign\Service\Payment\DTO\CardPaymentPayloadDTO;
use OCA\Libresign\Service\Payment\DTO\CardPaymentResultDTO;

interface ICardProvider extends IProvider {
	/**
	 * Initiate card payment (redirect flow)
	 */
	public function initiateCard(CardPaymentPayloadDTO $payload): CardPaymentResultDTO;
}
