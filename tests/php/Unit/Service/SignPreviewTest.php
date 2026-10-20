<?php

declare(strict_types=1);

namespace OCA\Libresign\Tests\Unit\Service;

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\Libresign\Db\FileElement;
use OCA\Libresign\Service\SignPreview;
use PHPUnit\Framework\TestCase;

final class SignPreviewTest extends TestCase {
	/** LibreSign's points from the bottom-left become mPDF's millimetres from the top-left. */
	public function testTheBoxLandsWhereTheSignatureGoes(): void {
		$box = new FileElement();
		// 144 x 72 points (2 x 1 inches), its top edge 72 points (1 inch) below the top of an A4 page.
		$box->setLlx(72);
		$box->setUrx(216);
		$box->setUry(842 - 72);
		$box->setLly(842 - 144);

		[$x, $y, $w, $h] = SignPreview::rect(842, $box);

		$this->assertEqualsWithDelta(25.4, $x, 0.001);
		$this->assertEqualsWithDelta(25.4, $y, 0.001);
		$this->assertEqualsWithDelta(50.8, $w, 0.001);
		$this->assertEqualsWithDelta(25.4, $h, 0.001);
	}
}
