<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Sponsorship;

use OCA\Libresign\Db\IdentifyMethod;
use OCA\Libresign\Service\IdentifyMethod\IIdentifyMethod;
use OCA\Libresign\Service\IdentifyMethodService;
use OCA\Libresign\Service\Sponsorship\SignerResolverService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * P0b: resolves the actual signer for settlement paths with no session
 * (listener, reconciliation job). Fails safe -- never falls back to
 * SignedEvent::getUser() (the file owner).
 */
final class SignerResolverServiceTest extends TestCase {
	private IdentifyMethodService&MockObject $identifyMethodService;
	private IUserManager&MockObject $userManager;
	private LoggerInterface&MockObject $logger;
	private SignerResolverService $service;

	protected function setUp(): void {
		$this->identifyMethodService = $this->createMock(IdentifyMethodService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new SignerResolverService(
			$this->identifyMethodService,
			$this->userManager,
			$this->logger,
		);
	}

	private function makeIdentifyMethod(string $key, string $value): IIdentifyMethod&MockObject {
		// Entity getters/setters are magic (__call via the base Entity class),
		// so a real instance is used rather than a mock of a method PHPUnit
		// cannot see declared -- the same pattern the rest of this codebase's
		// tests use for IdentifyMethod (see IdentifyMethodTest.php).
		$entity = new IdentifyMethod();
		$entity->setIdentifierKey($key);
		$entity->setIdentifierValue($value);

		$identifyMethod = $this->createMock(IIdentifyMethod::class);
		$identifyMethod->method('getEntity')->willReturn($entity);
		return $identifyMethod;
	}

	public function testResolvesAccountIdentifierDirectly(): void {
		$account = $this->makeIdentifyMethod(IdentifyMethodService::IDENTIFY_ACCOUNT, 'alice');

		$this->identifyMethodService
			->method('getIdentifyMethodsFromSignRequestId')
			->with(1)
			->willReturn(['account' => [$account]]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userManager->method('get')->with('alice')->willReturn($user);

		$result = $this->service->resolve(1);

		$this->assertTrue($result->resolved);
		$this->assertSame('alice', $result->userId);
	}

	public function testAccountIdentifierPointingAtDeletedUserIsUnresolved(): void {
		$account = $this->makeIdentifyMethod(IdentifyMethodService::IDENTIFY_ACCOUNT, 'ghost');

		$this->identifyMethodService
			->method('getIdentifyMethodsFromSignRequestId')
			->willReturn(['account' => [$account]]);
		$this->userManager->method('get')->with('ghost')->willReturn(null);

		$result = $this->service->resolve(1);

		$this->assertFalse($result->resolved);
		$this->assertSame('account_not_found', $result->reason);
	}

	public function testAccountTakesPriorityOverEmailWhenBothPresent(): void {
		$account = $this->makeIdentifyMethod(IdentifyMethodService::IDENTIFY_ACCOUNT, 'alice');
		$email = $this->makeIdentifyMethod(IdentifyMethodService::IDENTIFY_EMAIL, 'alice@example.com');

		$this->identifyMethodService
			->method('getIdentifyMethodsFromSignRequestId')
			->willReturn(['email' => [$email], 'account' => [$account]]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userManager->method('get')->with('alice')->willReturn($user);
		$this->userManager->expects($this->never())->method('getByEmail');

		$result = $this->service->resolve(1);

		$this->assertTrue($result->resolved);
		$this->assertSame('alice', $result->userId);
	}

	public function testEmailResolvesWhenExactlyOneAccountMatches(): void {
		$email = $this->makeIdentifyMethod(IdentifyMethodService::IDENTIFY_EMAIL, 'bob@example.com');

		$this->identifyMethodService
			->method('getIdentifyMethodsFromSignRequestId')
			->willReturn(['email' => [$email]]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$this->userManager->method('getByEmail')->with('bob@example.com')->willReturn([$user]);

		$result = $this->service->resolve(1);

		$this->assertTrue($result->resolved);
		$this->assertSame('bob', $result->userId);
	}

	public function testEmailWithNoAccountIsUnprovisionedAndUnresolved(): void {
		$email = $this->makeIdentifyMethod(IdentifyMethodService::IDENTIFY_EMAIL, 'nobody@example.com');

		$this->identifyMethodService
			->method('getIdentifyMethodsFromSignRequestId')
			->willReturn(['email' => [$email]]);
		$this->userManager->method('getByEmail')->willReturn([]);

		$result = $this->service->resolve(1);

		$this->assertFalse($result->resolved);
		$this->assertSame('email_unprovisioned', $result->reason);
	}

	public function testEmailSharedByMultipleAccountsIsAmbiguousAndUnresolved(): void {
		// The synchronous Account::getSigner() breaks this tie with the
		// current session's user; there is no session here, so it is left
		// unresolved rather than guessed at.
		$email = $this->makeIdentifyMethod(IdentifyMethodService::IDENTIFY_EMAIL, 'shared@example.com');

		$this->identifyMethodService
			->method('getIdentifyMethodsFromSignRequestId')
			->willReturn(['email' => [$email]]);
		$this->userManager->method('getByEmail')->willReturn([
			$this->createMock(IUser::class),
			$this->createMock(IUser::class),
		]);

		$result = $this->service->resolve(1);

		$this->assertFalse($result->resolved);
		$this->assertSame('email_ambiguous', $result->reason);
	}

	public function testNoAccountOrEmailIdentityIsUnresolved(): void {
		$whatsapp = $this->makeIdentifyMethod('whatsapp', '+254700000000');

		$this->identifyMethodService
			->method('getIdentifyMethodsFromSignRequestId')
			->willReturn(['whatsapp' => [$whatsapp]]);

		$result = $this->service->resolve(1);

		$this->assertFalse($result->resolved);
		$this->assertSame('no_identity', $result->reason);
	}
}
