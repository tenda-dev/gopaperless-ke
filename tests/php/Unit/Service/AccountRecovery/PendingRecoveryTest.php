<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\AccountRecovery;

use OCA\Libresign\Helper\RecoveryCookie;
use OCA\Libresign\Service\AccountRecovery\PendingRecovery;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PendingRecoveryTest extends TestCase {
	private const SECRET = 'instance-secret-A';
	private const UUID = '0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10';
	private const SIGNER = 'signer1';
	private const OTHER = 'someone-else';

	private function cookies(string $secret = self::SECRET): RecoveryCookie {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn($secret);

		return new RecoveryCookie($config);
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}

	private function service(?string $cookieValue): PendingRecovery {
		$request = $this->createMock(IRequest::class);
		$request->method('getCookie')->with(RecoveryCookie::NAME)->willReturn($cookieValue);

		return new PendingRecovery($request, $this->cookies());
	}

	private function validCookie(): string {
		$value = $this->cookies()->issue(self::UUID, self::SIGNER);
		self::assertNotNull($value);

		return $value;
	}

	public function testNoCookie(): void {
		self::assertSame(
			['pending' => false, 'clear' => false],
			$this->service(null)->evaluate($this->user(self::SIGNER)),
		);
	}

	public function testEmptyCookieIsTreatedAsNoCookie(): void {
		self::assertSame(
			['pending' => false, 'clear' => false],
			$this->service('')->evaluate($this->user(self::SIGNER)),
		);
	}

	public function testValidCookieForTheSignedInAccountIsPending(): void {
		self::assertSame(
			['pending' => true, 'clear' => false],
			$this->service($this->validCookie())->evaluate($this->user(self::SIGNER)),
		);
	}

	public function testValidCookieForAnotherAccountIsClearedNotPending(): void {
		self::assertSame(
			['pending' => false, 'clear' => true],
			$this->service($this->validCookie())->evaluate($this->user(self::OTHER)),
		);
	}

	public function testExpiredCookieIsClearedNotPending(): void {
		$expired = $this->cookies()->issue(self::UUID, self::SIGNER, time() - RecoveryCookie::TTL - 60);
		self::assertNotNull($expired);

		self::assertSame(
			['pending' => false, 'clear' => true],
			$this->service($expired)->evaluate($this->user(self::SIGNER)),
		);
	}

	public function testCookieSignedWithAnotherSecretIsClearedNotPending(): void {
		$foreign = $this->cookies('another-secret')->issue(self::UUID, self::SIGNER);
		self::assertNotNull($foreign);

		self::assertSame(
			['pending' => false, 'clear' => true],
			$this->service($foreign)->evaluate($this->user(self::SIGNER)),
		);
	}

	public function testTamperedSignatureIsClearedNotPending(): void {
		$value = $this->validCookie();
		$tampered = substr($value, 0, -1) . (substr($value, -1) === 'A' ? 'B' : 'A');

		self::assertSame(
			['pending' => false, 'clear' => true],
			$this->service($tampered)->evaluate($this->user(self::SIGNER)),
		);
	}

	public function testTamperedBodyIsClearedNotPending(): void {
		$value = $this->validCookie();
		$tampered = str_replace(self::UUID, '11111111-2222-4333-8444-555555555555', $value);

		self::assertSame(
			['pending' => false, 'clear' => true],
			$this->service($tampered)->evaluate($this->user(self::SIGNER)),
		);
	}

	/** @return array<string, array{0: string}> */
	public static function malformedCookies(): array {
		return [
			'garbage' => ['not-a-cookie'],
			'no signature' => ['v1~' . self::UUID . '~9999999999~x'],
			'too many dots' => ['a.b.c'],
			'oversized' => [str_repeat('a', 500)],
			'whitespace' => ['   '],
		];
	}

	#[DataProvider('malformedCookies')]
	public function testMalformedCookieIsClearedNotPending(string $value): void {
		self::assertSame(
			['pending' => false, 'clear' => true],
			$this->service($value)->evaluate($this->user(self::SIGNER)),
		);
	}

	public function testCookiePresentButNobodySignedInIsClearedNotPending(): void {
		self::assertSame(
			['pending' => false, 'clear' => true],
			$this->service($this->validCookie())->evaluate(null),
		);
	}

	public function testNoCookieAndNobodySignedInClearsNothing(): void {
		self::assertSame(
			['pending' => false, 'clear' => false],
			$this->service(null)->evaluate(null),
		);
	}

	public function testTheMatchIsOnTheBoundUidNotOnEmail(): void {
		// A different account with the same email still mismatches: the user
		// mock exposes no email at all and the result must not depend on one.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::OTHER);
		$user->expects(self::never())->method('getEMailAddress');

		self::assertSame(
			['pending' => false, 'clear' => true],
			$this->service($this->validCookie())->evaluate($user),
		);
	}
}
