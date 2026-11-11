<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\AccountRecovery;

use OCA\Libresign\Enum\RecoveryPlan;
use OCA\Libresign\Service\AccountRecovery\AccountRecoveryPlanner;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountRecoveryPlannerTest extends TestCase {
	private const OIDC = 'user_oidc';
	private const NATIVE = 'Database';

	/**
	 * current backend (null = no session user), signer backend, verified provider id, expected plan
	 *
	 * @return array<string, array{0: ?string, 1: string, 2: int, 3: RecoveryPlan}>
	 */
	public static function planProvider(): array {
		return [
			'oidc session to oidc signer, provider verified' => [self::OIDC, self::OIDC, 5, RecoveryPlan::CLIENT_SLS],
			'native session to oidc signer, provider verified' => [self::NATIVE, self::OIDC, 5, RecoveryPlan::SERVER_OIDC],
			'no session user to oidc signer, provider verified' => [null, self::OIDC, 5, RecoveryPlan::SERVER_OIDC],
			'unknown backend session to oidc signer, provider verified' => ['LDAP', self::OIDC, 5, RecoveryPlan::SERVER_OIDC],
			'oidc session to oidc signer, no provider' => [self::OIDC, self::OIDC, 0, RecoveryPlan::SERVER_LOGIN],
			'native session to oidc signer, no provider' => [self::NATIVE, self::OIDC, 0, RecoveryPlan::SERVER_LOGIN],
			'no session user to oidc signer, no provider' => [null, self::OIDC, 0, RecoveryPlan::SERVER_LOGIN],
			'negative provider counts as none' => [self::NATIVE, self::OIDC, -1, RecoveryPlan::SERVER_LOGIN],
			'oidc session to native signer, provider verified' => [self::OIDC, self::NATIVE, 5, RecoveryPlan::SERVER_NATIVE],
			'oidc session to native signer, no provider' => [self::OIDC, self::NATIVE, 0, RecoveryPlan::SERVER_NATIVE],
			'native session to native signer, provider verified' => [self::NATIVE, self::NATIVE, 5, RecoveryPlan::SERVER_NATIVE],
			'native session to native signer, no provider' => [self::NATIVE, self::NATIVE, 0, RecoveryPlan::SERVER_NATIVE],
			'no session user to native signer' => [null, self::NATIVE, 5, RecoveryPlan::SERVER_NATIVE],
			'unknown signer backend, provider verified' => [self::OIDC, 'LDAP', 5, RecoveryPlan::SERVER_LOGIN],
			'unknown signer backend, no provider' => [self::NATIVE, 'LDAP', 0, RecoveryPlan::SERVER_LOGIN],
			'empty signer backend name' => [self::NATIVE, '', 5, RecoveryPlan::SERVER_LOGIN],
			'near-miss oidc name is unknown' => [self::NATIVE, 'User_OIDC', 5, RecoveryPlan::SERVER_LOGIN],
			'near-miss native name is unknown' => [self::NATIVE, 'database', 5, RecoveryPlan::SERVER_LOGIN],
			'oidc-looking class name is unknown' => [self::NATIVE, 'OCA\\UserOIDC\\User_Backend', 5, RecoveryPlan::SERVER_LOGIN],
			'current near-miss oidc name is not oidc' => ['User_OIDC', self::OIDC, 5, RecoveryPlan::SERVER_OIDC],
		];
	}

	#[DataProvider('planProvider')]
	public function testPlan(?string $currentBackend, string $signerBackend, int $provider, RecoveryPlan $expected): void {
		$current = $currentBackend === null ? null : $this->user($currentBackend);
		$signer = $this->user($signerBackend);

		self::assertSame($expected, AccountRecoveryPlanner::plan($current, $signer, $provider));
	}

	public function testOnlyTheClientPlanIsNotServerHandled(): void {
		self::assertFalse(RecoveryPlan::CLIENT_SLS->isServerHandled());
		self::assertTrue(RecoveryPlan::SERVER_OIDC->isServerHandled());
		self::assertTrue(RecoveryPlan::SERVER_NATIVE->isServerHandled());
		self::assertTrue(RecoveryPlan::SERVER_LOGIN->isServerHandled());
	}

	private function user(string $backend): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getBackendClassName')->willReturn($backend);

		return $user;
	}
}
