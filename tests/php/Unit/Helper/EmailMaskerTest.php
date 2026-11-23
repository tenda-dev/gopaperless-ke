<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Helper;

use OCA\Libresign\Helper\EmailMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailMaskerTest extends TestCase {
	private const D = EmailMasker::DOTS;

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function validProvider(): array {
		$d = self::D;
		return [
			'plain' => ['bruno@gmail.com', "br{$d}@g{$d}.com"],
			'plus tag is dropped' => ['bruno+invoices@gmail.com', "br{$d}@g{$d}.com"],
			'two char local shows one' => ['al@outlook.com', "a{$d}@o{$d}.com"],
			'three char local shows one' => ['abc@example.com', "a{$d}@e{$d}.com"],
			'four char local shows two' => ['abcd@example.com', "ab{$d}@e{$d}.com"],
			'one char local shows none' => ['a@x.io', "{$d}@x{$d}.io"],
			'plus tag, one char base, subdomain, co.uk' => ['x+tag@sub.example.co.uk', "{$d}@s{$d}.co.uk"],
			'tag only local' => ['+tag@gmail.com', "{$d}@g{$d}.com"],
			'co.ke' => ['jane@mail.co.ke', "ja{$d}@m{$d}.co.ke"],
			'single label country suffix' => ['user@example.co', "us{$d}@e{$d}.co"],
			'dotted local counts dots' => ['a.b@mail.example.com', "a{$d}@m{$d}.com"],
			'com.au' => ['john.doe@company.com.au', "jo{$d}@c{$d}.com.au"],
			'second level label that is also a name' => ['me@mail.me.uk', "m{$d}@m{$d}.me.uk"],
			'case is kept' => ['Bruno@Gmail.COM', "Br{$d}@G{$d}.COM"],
			'test fixtures' => ['signer1@domain.test', "si{$d}@d{$d}.test"],
			'shared email fixture' => ['contact@domain.test', "co{$d}@d{$d}.test"],
			'surrounding spaces' => ['  bruno@gmail.com  ', "br{$d}@g{$d}.com"],
			'three labels, not a second level' => ['jane@mail.example.com', "ja{$d}@m{$d}.com"],
		];
	}

	#[DataProvider('validProvider')]
	public function testMasksAddress(string $input, string $expected): void {
		self::assertSame($expected, EmailMasker::mask($input));
	}

	/**
	 * @return array<string, array{0: ?string}>
	 */
	public static function unmaskableProvider(): array {
		return [
			'null' => [null],
			'empty' => [''],
			'blank' => ['   '],
			'no at' => ['plainaddress'],
			'two ats' => ['a@@b.com'],
			'no dot in domain' => ['user@localhost'],
			'ip literal' => ['user@[1.2.3.4]'],
			'bare ip' => ['user@1.2.3.4'],
			'quoted local with space' => ['"a b"@x.com'],
			'unquoted space' => ['a b@x.com'],
			'non ascii' => ['ü@exämple.com'],
			'local over 64 chars' => [str_repeat('a', 65) . '@x.com'],
			'over 254 chars' => [str_repeat('a', 60) . '@' . str_repeat('b.', 125) . 'com'],
			'leading dot domain' => ['a@.com'],
			'trailing dot' => ['a@x.com.'],
		];
	}

	#[DataProvider('unmaskableProvider')]
	public function testReturnsNullWhenItCannotMaskWithConfidence(?string $input): void {
		self::assertNull(EmailMasker::mask($input));
	}

	#[DataProvider('validProvider')]
	public function testInvariants(string $input): void {
		$masked = EmailMasker::mask($input);
		self::assertNotNull($masked);
		$trimmed = trim($input);
		$d = EmailMasker::DOTS;

		self::assertSame(1, substr_count($masked, '@'));
		self::assertSame(2, substr_count($masked, $d));
		self::assertStringNotContainsString('+', $masked);
		self::assertStringNotContainsString($trimmed, $masked);

		[$maskedLocal, $maskedDomain] = explode('@', $masked);
		$visible = substr($maskedLocal, 0, -strlen($d));
		$base = explode('+', explode('@', $trimmed)[0])[0];
		self::assertLessThanOrEqual(2, strlen($visible));
		if ($base !== '') {
			self::assertLessThan(strlen($base), strlen($visible), 'at least one base character stays hidden');
		}
		self::assertStringStartsWith($visible, $base);

		$labels = explode('.', explode('@', $trimmed)[1]);
		foreach (array_slice($labels, 1, -1) as $middle) {
			if (strlen($middle) > 2 && !in_array(strtolower($middle), ['com', 'org', 'net', 'gov', 'edu'], true)) {
				self::assertStringNotContainsString($middle, $maskedDomain);
			}
		}
	}

	public function testTagTextNeverAppears(): void {
		$masked = EmailMasker::mask('bruno+secret-project@gmail.com');
		self::assertNotNull($masked);
		self::assertStringNotContainsString('secret', $masked);
	}
}
