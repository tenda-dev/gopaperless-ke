<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Payment;

use OCA\Libresign\Enum\PaymentCapability;
use OCA\Libresign\Enum\PaymentFlowMode;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\ResolutionConfidence;
use OCA\Libresign\Service\Payment\MnoRoutingRegistry;
use OCA\Libresign\Tests\Unit\TestCase;

final class MnoRoutingRegistryTest extends TestCase {
	private MnoRoutingRegistry $registry;

	public function setUp(): void {
		parent::setUp();
		$this->registry = new MnoRoutingRegistry();
	}

	public function testKenyanSafaricomRoutesToDaraja(): void {
		$route = $this->registry->route(
			capability: PaymentCapability::MOBILE_MONEY,
			country: 'Kenya',
			region: 'KE',
			carrier: 'Safaricom',
			confidence: ResolutionConfidence::HIGH,
		);

		self::assertSame(PaymentProvider::DARAJA, $route->preferredProvider);
		self::assertSame(PaymentFlowMode::STK_PUSH, $route->mode);
		self::assertSame('KES', $route->currency);
		self::assertSame(ResolutionConfidence::HIGH, $route->confidence);
	}

	public function testKenyanMpesaRoutesToDaraja(): void {
		$route = $this->registry->route(
			capability: PaymentCapability::MOBILE_MONEY,
			country: 'Kenya',
			region: 'KE',
			carrier: 'M-Pesa',
			confidence: ResolutionConfidence::HIGH,
		);

		self::assertSame(PaymentProvider::DARAJA, $route->preferredProvider);
	}

	public function testKenyanAirtelRoutesToDpo(): void {
		$route = $this->registry->route(
			capability: PaymentCapability::MOBILE_MONEY,
			country: 'Kenya',
			region: 'KE',
			carrier: 'Airtel',
			confidence: ResolutionConfidence::HIGH,
		);

		self::assertSame(PaymentProvider::DPO, $route->preferredProvider);
		self::assertSame('Airtel', $route->mnoKey);
	}

	public function testCardFlowBypassesMnoRouting(): void {
		$route = $this->registry->route(
			capability: PaymentCapability::CARD,
			country: null,
			region: null,
			carrier: null,
			confidence: ResolutionConfidence::HIGH,
		);

		self::assertSame(PaymentProvider::DPO, $route->preferredProvider);
		self::assertSame(PaymentFlowMode::INSTRUCTIONS, $route->mode);
	}
}
