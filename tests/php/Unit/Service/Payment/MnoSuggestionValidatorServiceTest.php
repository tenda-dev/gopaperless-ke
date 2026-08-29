<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Payment;

use OCA\Libresign\Service\Payment\MnoSuggestionValidatorService;
use OCA\Libresign\Tests\Unit\TestCase;
use Psr\Log\LoggerInterface;

/**
 * canonicalMnoForOption() reverses a DPO provider/execution key back to the
 * canonical MNO identity. It keeps the two concepts separate: the DPO-native
 * key (airtelke) is for execution; the canonical MNO (airtel) is what the
 * identity cache stores.
 */
final class MnoSuggestionValidatorServiceTest extends TestCase {
	private MnoSuggestionValidatorService $service;

	public function setUp(): void {
		parent::setUp();
		$this->service = new MnoSuggestionValidatorService($this->createMock(LoggerInterface::class));
	}

	public function testMapsDpoNativeKeyToCanonicalMno(): void {
		self::assertSame('airtel', $this->service->canonicalMnoForOption('airtelke', 'KE'));
		self::assertSame('mpesa', $this->service->canonicalMnoForOption('safaricomstkv2', 'KE'));
	}

	public function testMapsBaseAliasToCanonicalMno(): void {
		self::assertSame('airtel', $this->service->canonicalMnoForOption('airtel', 'KE'));
		self::assertSame('mpesa', $this->service->canonicalMnoForOption('mpesa', 'KE'));
	}

	public function testExactMatchAvoidsFuzzyCollision(): void {
		// "selcom_webpay_airtel" must map to airtel, NOT vodacom ("selcom_webpay").
		self::assertSame('airtel', $this->service->canonicalMnoForOption('selcom_webpay_airtel', 'TZ'));
		self::assertSame('vodacom', $this->service->canonicalMnoForOption('selcom_webpay', 'TZ'));
	}

	public function testUnknownOrMissingReturnsNull(): void {
		self::assertNull($this->service->canonicalMnoForOption('totally-unknown', 'KE'));
		self::assertNull($this->service->canonicalMnoForOption('airtelke', null));
		self::assertNull($this->service->canonicalMnoForOption('airtelke', 'ZZ'));
		self::assertNull($this->service->canonicalMnoForOption(null, 'KE'));
	}
}
