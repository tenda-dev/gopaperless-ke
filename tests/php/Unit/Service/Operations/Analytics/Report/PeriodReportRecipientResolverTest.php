<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Operations\Analytics\Report;

use OCA\Libresign\Service\Operations\Analytics\Report\PeriodReportRecipientResolver;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Mail\IMailer;
use PHPUnit\Framework\MockObject\MockObject;

final class PeriodReportRecipientResolverTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private IGroupManager&MockObject $groupManager;
	private IMailer&MockObject $mailer;
	private PeriodReportRecipientResolver $resolver;
	/** @var array<string, list<IUser>> */
	private array $membership = [];

	public function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->mailer->method('validateMailAddress')->willReturnCallback(
			static fn (string $address): bool => str_contains($address, '@') && !str_contains($address, ' '),
		);
		$this->groupManager->method('get')->willReturnCallback(function (string $id): ?IGroup {
			if (!array_key_exists($id, $this->membership)) {
				return null;
			}
			$group = $this->createMock(IGroup::class);
			$group->method('searchUsers')->willReturnCallback(
				fn (string $search, ?int $limit, ?int $offset): array => array_slice($this->membership[$id], $offset ?? 0, $limit),
			);
			return $group;
		});
		$this->resolver = new PeriodReportRecipientResolver($this->appConfig, $this->groupManager, $this->mailer);
	}

	private function configure(array $groups): void {
		$this->appConfig->method('hasKey')->willReturn(true);
		$this->appConfig->method('getValueArray')->willReturn($groups);
	}

	private function user(string $email, bool $enabled = true, string $name = 'Some Body'): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn($email);
		$user->method('isEnabled')->willReturn($enabled);
		$user->method('getDisplayName')->willReturn($name);
		$user->method('getUID')->willReturn($email);
		return $user;
	}

	public function testKeyAbsentMeansNoRecipientsEvenThoughAccessServiceWouldFallBackToAdmin(): void {
		$this->appConfig->method('hasKey')->willReturn(false);
		$this->appConfig->expects($this->never())->method('getValueArray');
		// If the resolver used the access-service fallback it would look up 'admin'.
		$this->membership = ['admin' => [$this->user('root@example.org')]];

		$result = $this->resolver->resolve();

		$this->assertSame([], $result['recipients']);
	}

	public function testEmptyListMeansNoRecipients(): void {
		$this->configure([]);

		$this->assertSame([], $this->resolver->resolve()['recipients']);
	}

	public function testMistypedKeyMeansNoRecipients(): void {
		$this->appConfig->method('hasKey')->willReturn(true);
		$this->appConfig->method('getValueArray')->willThrowException(new AppConfigTypeConflictException('x'));

		$this->assertSame([], $this->resolver->resolve()['recipients']);
	}

	public function testAdminsAreNotAddedImplicitly(): void {
		$this->configure(['ops']);
		$this->membership = [
			'ops' => [$this->user('ops@example.org')],
			'admin' => [$this->user('root@example.org')],
		];

		$emails = array_column($this->resolver->resolve()['recipients'], 'email');

		$this->assertSame(['ops@example.org'], $emails);
	}

	public function testMissingGroupIsCountedAndSkipped(): void {
		$this->configure(['ghost', 'ops']);
		$this->membership = ['ops' => [$this->user('ops@example.org')]];

		$result = $this->resolver->resolve();

		$this->assertSame(1, $result['skipped']['missingGroups']);
		$this->assertCount(1, $result['recipients']);
	}

	public function testSkipsDisabledUsersMissingAndInvalidEmails(): void {
		$this->configure(['ops']);
		$this->membership = ['ops' => [
			$this->user('ok@example.org'),
			$this->user('off@example.org', false),
			$this->user(''),
			$this->user('not valid'),
		]];

		$result = $this->resolver->resolve();

		$this->assertSame(['ok@example.org'], array_column($result['recipients'], 'email'));
		$this->assertSame(1, $result['skipped']['disabled']);
		$this->assertSame(1, $result['skipped']['noEmail']);
		$this->assertSame(1, $result['skipped']['invalidEmail']);
	}

	public function testDeduplicatesByLowerCasedEmailAcrossGroups(): void {
		$this->configure(['a', 'b']);
		$this->membership = [
			'a' => [$this->user('Dup@Example.org', true, 'First')],
			'b' => [$this->user('dup@example.org', true, 'Second'), $this->user('other@example.org')],
		];

		$result = $this->resolver->resolve();

		$this->assertSame(['Dup@Example.org', 'other@example.org'], array_column($result['recipients'], 'email'));
		$this->assertSame(1, $result['skipped']['duplicates']);
	}

	public function testNameFallsBackToEmail(): void {
		$this->configure(['ops']);
		$this->membership = ['ops' => [$this->user('ops@example.org', true, '  ')]];

		$this->assertSame('ops@example.org', $this->resolver->resolve()['recipients'][0]['name']);
	}

	public function testMembershipChangesBetweenCallsAreReflected(): void {
		$this->configure(['ops']);
		$this->membership = ['ops' => [$this->user('a@example.org')]];
		$this->assertCount(1, $this->resolver->resolve()['recipients']);

		$this->membership = ['ops' => [$this->user('a@example.org'), $this->user('b@example.org')]];
		$this->assertCount(2, $this->resolver->resolve()['recipients']);
	}

	public function testPagesThroughLargeGroupsAndCapsTheScan(): void {
		$this->configure(['big']);
		$users = [];
		for ($i = 0; $i < PeriodReportRecipientResolver::MAX_USERS + 50; $i++) {
			$users[] = $this->user("u{$i}@example.org");
		}
		$this->membership = ['big' => $users];

		$result = $this->resolver->resolve();

		$this->assertCount(PeriodReportRecipientResolver::MAX_USERS, $result['recipients']);
		$this->assertTrue($result['skipped']['capped']);
	}
}
