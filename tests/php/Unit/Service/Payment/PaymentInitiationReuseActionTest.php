<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Payment;

use OCA\Libresign\Service\Payment\PaymentInitiationService;
use OCA\Libresign\Tests\Unit\TestCase;
use ReflectionMethod;

/**
 * Covers the pure decision that governs how a reusable pending card credit
 * purchase is reconciled with the requested sponsorship-workflow draft pointer:
 * attach when the pending has none, reuse when it matches, recreate (never
 * overwrite) when it points at a different draft.
 */
final class PaymentInitiationReuseActionTest extends TestCase {
	private function decide(?string $pending, ?string $requested): string {
		$m = new ReflectionMethod(PaymentInitiationService::class, 'resolveSponsorshipWorkflowReuseAction');
		$m->setAccessible(true);
		return $m->invoke(null, $pending, $requested);
	}

	public function testAttachWhenPendingHasNoPointer(): void {
		self::assertSame('attach', $this->decide(null, 'file-b'));
		self::assertSame('attach', $this->decide('', 'file-b'));
	}

	public function testReuseWhenPointerMatches(): void {
		self::assertSame('reuse', $this->decide('file-a', 'file-a'));
	}

	public function testRecreateWhenPointerDiffers(): void {
		self::assertSame('recreate', $this->decide('file-a', 'file-b'));
	}

	public function testClearWhenRequestedAbsentButPendingHasPointer(): void {
		self::assertSame('clear', $this->decide('file-a', null));
		self::assertSame('clear', $this->decide('file-a', ''));
	}

	public function testReuseWhenNeitherHasPointer(): void {
		self::assertSame('reuse', $this->decide(null, null));
		self::assertSame('reuse', $this->decide('', ''));
	}
}
