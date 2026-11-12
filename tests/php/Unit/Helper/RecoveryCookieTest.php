<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Helper;

use OCA\Libresign\Helper\RecoveryCookie;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecoveryCookieTest extends TestCase {
	private const SECRET = 'instance-secret-A';
	private const UUID = '0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10';
	private const OTHER_UUID = '11111111-2222-4333-8444-555555555555';
	private const UID = 'signer1';
	private const NOW = 1_800_000_000;

	private function cookie(string $secret = self::SECRET): RecoveryCookie {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn($secret);

		return new RecoveryCookie($config);
	}

	private static function b64url(string $raw): string {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}

	/** Independent of the class under test: signs an arbitrary body. */
	private static function signed(string $body, string $secret = self::SECRET, string $prefix = 'gopaperless-ws1-recovery-sig:'): string {
		return $body . '.' . self::b64url(hash_hmac('sha256', $prefix . $body, $secret, true));
	}

	private static function tag(string $uuid = self::UUID, string $uid = self::UID, string $secret = self::SECRET): string {
		return self::b64url(hash_hmac('sha256', 'gopaperless-ws1-recovery-uid:' . $uuid . ':' . $uid, $secret, true));
	}

	private function issued(): string {
		$value = $this->cookie()->issue(self::UUID, self::UID, self::NOW);
		self::assertNotNull($value);

		return $value;
	}

	public function testRoundTrip(): void {
		$verified = $this->cookie()->verify($this->issued(), self::NOW);

		self::assertSame(['uuid' => self::UUID, 'uidTag' => self::tag()], $verified);
	}

	public function testValueHasTheDocumentedShape(): void {
		$value = $this->issued();

		self::assertMatchesRegularExpression(
			'/^1~' . self::UUID . '~' . (self::NOW + RecoveryCookie::TTL) . '~[A-Za-z0-9_-]{43}\.[A-Za-z0-9_-]{43}$/',
			$value,
		);
		self::assertLessThanOrEqual(200, strlen($value));
	}

	public function testNameAndTtl(): void {
		self::assertSame('gp_recover', RecoveryCookie::NAME);
		self::assertSame(600, RecoveryCookie::TTL);
	}

	public function testDefaultClockWorks(): void {
		$cookie = $this->cookie();
		$value = $cookie->issue(self::UUID, self::UID);

		self::assertNotNull($value);
		self::assertNotNull($cookie->verify($value));
	}

	public function testNoRawUidOrEmailInTheValue(): void {
		$value = $this->cookie()->issue(self::UUID, 'bruno@gmail.com', self::NOW);

		self::assertNotNull($value);
		self::assertStringNotContainsString('bruno', $value);
		self::assertStringNotContainsString('gmail', $value);
		self::assertStringNotContainsString('@', $value);
	}

	public function testUidTagBindsUuidAndUid(): void {
		$cookie = $this->cookie();
		$verified = $cookie->verify($this->issued(), self::NOW);
		self::assertNotNull($verified);

		self::assertTrue($cookie->isBoundTo($verified, self::UID));
		self::assertFalse($cookie->isBoundTo($verified, 'signer2'));
		self::assertFalse($cookie->isBoundTo($verified, ''));
		self::assertFalse($cookie->isBoundTo(['uuid' => self::OTHER_UUID, 'uidTag' => $verified['uidTag']], self::UID));
		self::assertFalse($cookie->isBoundTo(['uuid' => self::UUID, 'uidTag' => self::tag(self::UUID, 'signer2')], self::UID));
	}

	public function testUidTagIsNotValidAsSignature(): void {
		// A body whose signature was made with the uid prefix.
		$body = '1~' . self::UUID . '~' . (self::NOW + 100) . '~' . self::tag();

		self::assertNull($this->cookie()->verify(self::signed($body, self::SECRET, 'gopaperless-ws1-recovery-uid:'), self::NOW));
		self::assertNotNull($this->cookie()->verify(self::signed($body), self::NOW), 'control: the right prefix verifies');
	}

	public function testOtherSecretFails(): void {
		$value = $this->issued();

		self::assertNull($this->cookie('instance-secret-B')->verify($value, self::NOW));
	}

	public function testEmptySecretFailsSafe(): void {
		self::assertNull($this->cookie('')->issue(self::UUID, self::UID, self::NOW));
		self::assertNull($this->cookie('')->verify(self::signed('1~' . self::UUID . '~' . (self::NOW + 100) . '~' . self::tag(), ''), self::NOW));
	}

	public function testExpiryBoundary(): void {
		$cookie = $this->cookie();
		$value = $this->issued();
		$exp = self::NOW + RecoveryCookie::TTL;

		self::assertNotNull($cookie->verify($value, $exp - 1));
		self::assertNull($cookie->verify($value, $exp), 'exp == now is expired');
		self::assertNull($cookie->verify($value, $exp + 1));
	}

	public function testIssueRejectsBadInput(): void {
		$cookie = $this->cookie();

		self::assertNull($cookie->issue('not-a-uuid', self::UID, self::NOW));
		self::assertNull($cookie->issue('', self::UID, self::NOW));
		self::assertNull($cookie->issue(self::UUID . '~x', self::UID, self::NOW));
		self::assertNull($cookie->issue(self::UUID, '', self::NOW));
	}

	/** @return array<string, array{0: ?string}> */
	public static function garbageProvider(): array {
		return [
			'null' => [null],
			'empty' => [''],
			'dot only' => ['.'],
			'no dot' => ['abc'],
			'two dots' => ['a.b.c'],
			'short sig' => ['1~' . self::UUID . '~9999999999~' . self::tag() . '.abc'],
			'oversize' => [str_repeat('a', 201)],
			'tilde soup' => ['~~~.' . str_repeat('A', 43)],
		];
	}

	#[DataProvider('garbageProvider')]
	public function testGarbageIsRejected(?string $raw): void {
		self::assertNull($this->cookie()->verify($raw, self::NOW));
	}

	public function testTruncationIsRejected(): void {
		$value = $this->issued();

		foreach ([1, 5, 43, 44] as $cut) {
			self::assertNull($this->cookie()->verify(substr($value, 0, -$cut), self::NOW), "cut $cut");
		}
	}

	/** @return array<string, array{0: string}> */
	public static function tamperedFieldProvider(): array {
		// Each is a field change applied to a validly issued value without re-signing.
		return [
			'version' => ['version'],
			'uuid' => ['uuid'],
			'exp' => ['exp'],
			'uid_tag' => ['uid_tag'],
			'sig' => ['sig'],
		];
	}

	#[DataProvider('tamperedFieldProvider')]
	public function testTamperingAnyFieldIsRejected(string $field): void {
		[$body, $sig] = explode('.', $this->issued());
		[$v, $uuid, $exp, $tag] = explode('~', $body);
		$flip = static fn (string $s): string => substr($s, 0, -1) . ($s[-1] === 'A' ? 'B' : 'A');

		$mutated = match ($field) {
			'version' => implode('~', ['2', $uuid, $exp, $tag]) . '.' . $sig,
			'uuid' => implode('~', [$v, self::OTHER_UUID, $exp, $tag]) . '.' . $sig,
			'exp' => implode('~', [$v, $uuid, (string)((int)$exp + 1000), $tag]) . '.' . $sig,
			'uid_tag' => implode('~', [$v, $uuid, $exp, $flip($tag)]) . '.' . $sig,
			'sig' => $body . '.' . $flip($sig),
		};

		self::assertNull($this->cookie()->verify($mutated, self::NOW));
	}

	/**
	 * Correctly signed but malformed bodies: the signature alone is not enough.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function signedButMalformedProvider(): array {
		$future = (string)(self::NOW + 100);
		$tag = self::tag();

		return [
			'unknown version' => ["2~" . self::UUID . "~$future~$tag"],
			'empty version' => ["~" . self::UUID . "~$future~$tag"],
			'missing field' => ['1~' . self::UUID . "~$future"],
			'extra field' => ['1~' . self::UUID . "~$future~$tag~x"],
			'bad uuid' => ["1~not-a-uuid~$future~$tag"],
			'uuid with newline' => ["1~" . self::UUID . "\n~$future~$tag"],
			'non-digit exp' => ['1~' . self::UUID . "~12a4~$tag"],
			'signed exp' => ['1~' . self::UUID . "~-5~$tag"],
			'eleven digit exp' => ['1~' . self::UUID . "~12345678901~$tag"],
			'empty exp' => ['1~' . self::UUID . "~~$tag"],
			'short tag' => ['1~' . self::UUID . "~$future~abc"],
			'long tag' => ['1~' . self::UUID . "~$future~" . $tag . 'A'],
			'tag with bad chars' => ['1~' . self::UUID . "~$future~" . str_repeat('+', 43)],
		];
	}

	#[DataProvider('signedButMalformedProvider')]
	public function testSignedButMalformedBodyIsRejected(string $body): void {
		self::assertNull($this->cookie()->verify(self::signed($body), self::NOW));
	}

	public function testOversizeButValidlySignedIsRejected(): void {
		$body = '1~' . self::UUID . '~' . (self::NOW + 100) . '~' . self::tag() . str_repeat('A', 100);

		self::assertNull($this->cookie()->verify(self::signed($body), self::NOW));
	}
}
