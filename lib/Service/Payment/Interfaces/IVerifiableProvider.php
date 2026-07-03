<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment\Interfaces;

interface IVerifiableProvider extends IProvider
{
	public function verifyStatus(string $reference): string;

	public function query(string $reference): array;

	public function cancel(string $reference): array;
}
