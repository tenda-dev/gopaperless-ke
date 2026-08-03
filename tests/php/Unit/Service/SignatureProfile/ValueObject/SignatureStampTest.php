<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\SignatureProfile\ValueObject;

use OCA\Libresign\Service\SignatureProfile\ValueObject\SignatureStamp;
use PHPUnit\Framework\TestCase;

final class SignatureStampTest extends TestCase {
	public function testFromArrayWithBoolean(): void {
		$enabled = SignatureStamp::fromArray(true);
		$this->assertTrue($enabled->isEnabled());
		$this->assertNull($enabled->getRenderMode());

		$disabled = SignatureStamp::fromArray(false);
		$this->assertFalse($disabled->isEnabled());
	}

	public function testFromArrayWithNullReturnsDefault(): void {
		$stamp = SignatureStamp::fromArray(null);

		$this->assertTrue($stamp->isEnabled());
		$this->assertNull($stamp->getRenderMode());
		$this->assertNull($stamp->getTextTemplate());
	}

	public function testFromArrayWithObjectOverrides(): void {
		$stamp = SignatureStamp::fromArray([
			'enabled' => true,
			'renderMode' => 'GRAPHIC_ONLY',
			'textTemplate' => 'Signed by {{ SignerCommonName }}',
			'signatureFontSize' => 18.5,
			'templateFontSize' => 9.0,
			'width' => 400.0,
			'height' => 120.0,
		]);

		$this->assertTrue($stamp->isEnabled());
		$this->assertSame('GRAPHIC_ONLY', $stamp->getRenderMode());
		$this->assertSame('Signed by {{ SignerCommonName }}', $stamp->getTextTemplate());
		$this->assertSame(18.5, $stamp->getSignatureFontSize());
		$this->assertSame(9.0, $stamp->getTemplateFontSize());
		$this->assertSame(400.0, $stamp->getWidth());
		$this->assertSame(120.0, $stamp->getHeight());
	}

	public function testToArrayEmitsOnlyNonNullOverrides(): void {
		$stamp = new SignatureStamp(
			enabled: true,
			renderMode: 'DESCRIPTION_ONLY',
			signatureFontSize: 16.0,
		);

		$this->assertSame([
			'enabled' => true,
			'renderMode' => 'DESCRIPTION_ONLY',
			'signatureFontSize' => 16.0,
		], $stamp->toArray());
	}

	public function testNullableStringAndFloatCoerceEmptyToNull(): void {
		$stamp = SignatureStamp::fromArray([
			'renderMode' => '',
			'textTemplate' => '',
			'signatureFontSize' => '',
			'templateFontSize' => '',
			'width' => '',
			'height' => '',
		]);

		$this->assertNull($stamp->getRenderMode());
		$this->assertNull($stamp->getTextTemplate());
		$this->assertNull($stamp->getSignatureFontSize());
		$this->assertNull($stamp->getTemplateFontSize());
		$this->assertNull($stamp->getWidth());
		$this->assertNull($stamp->getHeight());
	}
}
