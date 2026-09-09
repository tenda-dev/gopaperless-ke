<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Payment\DTO;

use OCA\Libresign\Service\Payment\DTO\CardPaymentPayloadDTO;
use OCA\Libresign\Tests\Unit\TestCase;

/**
 * returnUrl is now an absolute, client-facing URL (built by the frontend
 * from window.location.origin -- see usePayment.ts's buildPaymentReturnUrl).
 * Its host allowlist and DPO-specific fragment handling are enforced at
 * the provider boundary -- see DpoProviderTest -- since how it's used is
 * provider-specific. This DTO just carries the value through unchanged.
 */
final class CardPaymentPayloadDTOTest extends TestCase {
	private function makeValidArgs(array $overrides = []): array {
		return array_merge([
			'amount' => 10.0,
			'currency' => 'KES',
			'userId' => 'user-1',
			'email' => 'user@example.com',
			'redirectUrl' => 'https://app.example.com/f/payment/return',
		], $overrides);
	}

	public function testReturnUrlDefaultsToNull(): void {
		$dto = new CardPaymentPayloadDTO(...$this->makeValidArgs());
		self::assertNull($dto->returnUrl);
	}

	public function testReturnUrlIsCarriedThroughAndIncludedInToArray(): void {
		$dto = new CardPaymentPayloadDTO(...$this->makeValidArgs([
			'returnUrl' => 'https://app.example.com/f/sign/abc-123?retrySign=true#top',
		]));

		self::assertSame('https://app.example.com/f/sign/abc-123?retrySign=true#top', $dto->returnUrl);
		self::assertSame('https://app.example.com/f/sign/abc-123?retrySign=true#top', $dto->toArray()['returnUrl']);
	}
}
