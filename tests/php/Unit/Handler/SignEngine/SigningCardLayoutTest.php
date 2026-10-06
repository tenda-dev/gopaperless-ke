<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Handler\SignEngine;

use OCA\Libresign\Handler\SignEngine\PhpNativeHandler;
use PHPUnit\Framework\TestCase;

final class SigningCardLayoutTest extends TestCase {
	private string $handwriting;

	protected function setUp(): void {
		// 300 x 100: the handwriting has to keep this 3:1 shape on the card.
		$image = imagecreatetruecolor(300, 100);
		$this->handwriting = tempnam(sys_get_temp_dir(), 'card') . '.png';
		imagepng($image, $this->handwriting);
	}

	protected function tearDown(): void {
		@unlink($this->handwriting);
	}

	private function card(string $name): array {
		return [
			'name' => $name,
			'issuer' => 'Signa Hardware CA',
			'time' => new \DateTimeImmutable('2026-10-05T11:32:08Z'),
			'handwriting' => $this->handwriting,
		];
	}

	public function testTheCardShowsTheNameIssuerAndTheTimeInNairobi(): void {
		[$xObject] = PhpNativeHandler::signingCardLayout($this->card('Jane Wanjiku Njoroge'), 200.0, 90.0);

		self::assertStringContainsString('(JANE WANJIKU NJOROGE) Tj', $xObject->stream);
		self::assertStringContainsString('(ISSUER: SIGNA HARDWARE CA) Tj', $xObject->stream);
		self::assertStringContainsString('(TIMESTAMP: 05 Oct 2026, 14:32:08 EAT) Tj', $xObject->stream);
		self::assertStringNotContainsString('re S', $xObject->stream, 'no border around the card');
		self::assertSame('/Helvetica-Bold', $xObject->resources['Font']['F2']['BaseFont']);
	}

	public function testALongNameShrinksToFitInsteadOfRunningOffTheBox(): void {
		$name = 'Bartholomew Ochieng Wanjiku Kamau Otieno Njoroge';
		foreach ([[200.0, 90.0], [120.0, 60.0]] as [$width, $height]) {
			[$xObject] = PhpNativeHandler::signingCardLayout($this->card($name), $width, $height);
			preg_match_all('/\/F\d (\d+\.\d+) Tf [\d. ]+ rg (\d+\.\d+) [\d.]+ Td/', $xObject->stream, $lines, PREG_SET_ORDER);
			self::assertCount(3, $lines);
			foreach ($lines as [, $size, $x]) {
				self::assertGreaterThan(0.0, (float)$x);
				self::assertLessThan($width / 2, (float)$x, 'centred text starts in the left half');
				self::assertGreaterThan(0.0, (float)$size);
			}
		}
	}

	public function testTheHandwritingKeepsItsShapeAboveTheText(): void {
		[$xObject, $frame] = PhpNativeHandler::signingCardLayout($this->card('Jane Njoroge'), 200.0, 90.0);
		[$x, $y, $w, $h] = $frame;

		self::assertEqualsWithDelta(3.0, $w / $h, 0.01);
		self::assertGreaterThanOrEqual(0.0, $x);
		self::assertLessThanOrEqual(200.0, $x + $w);
		self::assertLessThanOrEqual(90.0, $y + $h);
		preg_match('/\(JANE NJOROGE\)/', $xObject->stream);
		preg_match_all('/([\d.]+) ([\d.]+) Td \(JANE NJOROGE\)/', $xObject->stream, $name);
		self::assertGreaterThan((float)$name[2][0], $y, 'the handwriting sits above the name');
	}

	/** The horizontal A/B card: handwriting and name left, ISSUER over TIMESTAMP right. */
	public function testTheHorizontalCardKeepsTheHandwritingWholeOnTheLeft(): void {
		foreach ([[240.0, 70.0], [160.0, 50.0], [300.0, 110.0]] as [$width, $height]) {
			$card = $this->card('Bartholomew Ochieng Wanjiku Kamau') + ['layout' => 'horizontal'];
			[$xObject, $frame] = PhpNativeHandler::signingCardLayout($card, $width, $height);
			[$x, $y, $w, $h] = $frame;

			self::assertEqualsWithDelta(3.0, $w / $h, 0.01, 'the handwriting keeps its shape');
			self::assertGreaterThanOrEqual(0.0, $x);
			self::assertLessThanOrEqual($width * 0.56, $x + $w, 'the handwriting stays in the left column');
			self::assertLessThanOrEqual($height, $y + $h, 'the handwriting is not cut off at the top');

			preg_match_all('/([\d.]+) Tf ([\d.]+) Tc [\d. ]+ rg ([\d.]+) ([\d.]+) Td \(([^)]*)\) Tj/', $xObject->stream, $lines, PREG_SET_ORDER);
			$at = [];
			foreach ($lines as [, $size, , $lx, $ly, $text]) {
				$at[$text] = [(float)$lx, (float)$ly, (float)$size];
			}
			$time = '05 Oct 2026, 14:32:08 EAT';
			self::assertArrayHasKey('ISSUER', $at);
			self::assertArrayHasKey('TIMESTAMP', $at);
			self::assertArrayHasKey($time, $at);
			self::assertGreaterThan($at['SIGNA HARDWARE CA'][1], $at['ISSUER'][1], 'label over value');
			self::assertGreaterThan($at['TIMESTAMP'][1], $at['SIGNA HARDWARE CA'][1], 'ISSUER above TIMESTAMP');

			// The long name may wrap; every word is there, in order, on the left.
			$name = array_diff_key($at, array_flip(['ISSUER', 'SIGNA HARDWARE CA', 'TIMESTAMP', $time]));
			uasort($name, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
			self::assertSame('BARTHOLOMEW OCHIENG WANJIKU KAMAU', implode(' ', array_keys($name)));
			self::assertEqualsWithDelta(min(array_column($name, 1)), $at[$time][1], 0.01, 'TIMESTAMP level with the name');
			foreach ($name as [$nx, $ny, $size]) {
				self::assertLessThan($at['ISSUER'][0], $nx, 'name on the left');
				self::assertLessThan($y, $ny, 'the name sits under the handwriting');
				self::assertGreaterThanOrEqual($at[$time][2] * 0.6, $size, 'a long name stays legible next to the values');
			}
			self::assertGreaterThanOrEqual($at['SIGNA HARDWARE CA'][2] * 0.99, $at[$time][2], 'one scale on the right');
		}
	}
}
