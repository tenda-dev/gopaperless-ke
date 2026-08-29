<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Command\Diagnose;

use OCA\Libresign\Command\Diagnose\DuplicateEmails;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class DuplicateEmailsTest extends TestCase {
	private IUserManager&MockObject $userManager;
	private DuplicateEmails $command;

	public function setUp(): void {
		parent::setUp();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->command = new DuplicateEmails($this->userManager);
	}

	private function makeUser(string $uid, ?string $email, string $displayName = '', string $backend = 'Database'): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($email);
		$user->method('getDisplayName')->willReturn($displayName ?: $uid);
		$user->method('getBackendClassName')->willReturn($backend);
		return $user;
	}

	public function testReportsNoDuplicatesWhenAllEmailsUnique(): void {
		$users = [
			$this->makeUser('alice', 'alice@example.com'),
			$this->makeUser('bob', 'bob@example.com'),
			$this->makeUser('carol', null), // no email set — must be ignored, not crash
		];
		$this->userManager->expects($this->once())
			->method('callForAllUsers')
			->willReturnCallback(function (\Closure $callback) use ($users): void {
				foreach ($users as $user) {
					$callback($user);
				}
			});

		[$status, $output] = $this->runCommand();

		$this->assertSame(Command::SUCCESS, $status);
		$this->assertStringContainsString('No duplicate emails found', $output);
	}

	public function testReportsAccountsSharingAnEmail(): void {
		$users = [
			$this->makeUser('jdoe', 'jane@example.com', 'Jane (local)'),
			$this->makeUser('a1b2c3d4-uuid', 'JANE@example.com', 'Jane (oidc)'), // same email, different case
			$this->makeUser('other', 'other@example.com', 'Other'),
		];
		$this->userManager->expects($this->once())
			->method('callForAllUsers')
			->willReturnCallback(function (\Closure $callback) use ($users): void {
				foreach ($users as $user) {
					$callback($user);
				}
			});

		[$status, $output] = $this->runCommand();

		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringContainsString('jdoe', $output);
		$this->assertStringContainsString('a1b2c3d4-uuid', $output);
		$this->assertStringContainsString('jane@example.com', $output);
		$this->assertStringNotContainsString('other@example.com', $output);
		$this->assertStringContainsString('Found 1 email address(es)', $output);
	}

	private function runCommand(): array {
		$output = new BufferedOutput();
		$status = $this->command->run(new ArrayInput([]), $output);

		return [$status, $output->fetch()];
	}
}
