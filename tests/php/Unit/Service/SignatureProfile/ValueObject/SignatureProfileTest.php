<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\SignatureProfile\ValueObject;

use OCA\Libresign\Service\SignatureProfile\ValueObject\SignatureProfile;
use OCA\Libresign\Service\SignatureProfile\ValueObject\SignatureStamp;
use PHPUnit\Framework\TestCase;

final class SignatureProfileTest extends TestCase {
	public function testFromArrayUsesDefaultOnFlagsForMissingKeys(): void {
		$profile = SignatureProfile::fromArray([]);

		$this->assertTrue($profile->shouldRenderFooter());
		$this->assertTrue($profile->shouldRenderQrCode());
		$this->assertTrue($profile->shouldRenderStamp());
		$this->assertTrue($profile->shouldRenderAuditInfo());
	}

	public function testFromArrayReadsExplicitFlags(): void {
		$profile = SignatureProfile::fromArray([
			'footer' => false,
			'qr' => false,
			'auditInfo' => false,
		]);

		$this->assertFalse($profile->shouldRenderFooter());
		$this->assertFalse($profile->shouldRenderQrCode());
		$this->assertFalse($profile->shouldRenderAuditInfo());
		$this->assertTrue($profile->shouldRenderStamp());
	}

	public function testToArraySerialization(): void {
		$profile = new SignatureProfile(
			footer: false,
			qr: true,
			stamp: new SignatureStamp(
				enabled: true,
				renderMode: 'DESCRIPTION_ONLY',
				signatureFontSize: 14.0,
			),
			auditInfo: true,
		);

		$this->assertSame([
			'footer' => false,
			'qr' => true,
			'stamp' => [
				'enabled' => true,
				'renderMode' => 'DESCRIPTION_ONLY',
				'signatureFontSize' => 14.0,
			],
			'auditInfo' => true,
		], $profile->toArray());
	}

	public function testFromArrayHandlesLegacyBooleanStamp(): void {
		$disabled = SignatureProfile::fromArray(['stamp' => false]);
		$this->assertFalse($disabled->shouldRenderStamp());

		$enabled = SignatureProfile::fromArray(['stamp' => true]);
		$this->assertTrue($enabled->shouldRenderStamp());
		$this->assertNull($enabled->getStamp()->getRenderMode());
	}
}
