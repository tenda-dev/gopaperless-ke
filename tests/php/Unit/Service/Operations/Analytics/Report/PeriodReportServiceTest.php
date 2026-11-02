<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Operations\Analytics\Report;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Libresign\Service\Operations\Analytics\Report\PeriodReportBuilder;
use OCA\Libresign\Service\Operations\Analytics\Report\PeriodReportMailer;
use OCA\Libresign\Service\Operations\Analytics\Report\PeriodReportRecipientResolver;
use OCA\Libresign\Service\Operations\Analytics\Report\PeriodReportService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Default schedule: weekly, Monday 09:00 Africa/Nairobi (06:00Z).
 * 2026-10-26 is a Monday.
 */
final class PeriodReportServiceTest extends TestCase {
	private const SECRET_EMAIL = 'secret.person@example.org';
	private const SECRET_NAME = 'Secret Person';

	private IAppConfig&MockObject $appConfig;
	private ITimeFactory&MockObject $time;
	private PeriodReportRecipientResolver&MockObject $recipients;
	private PeriodReportBuilder&MockObject $builder;
	private PeriodReportMailer&MockObject $mailer;
	private LoggerInterface&MockObject $logger;
	private PeriodReportService $service;
	/** @var array<string, mixed> */
	private array $store = [];
	/** @var list<string> keys written, in order */
	private array $writes = [];
	private int $now;
	/** @var list<array{string, array<string, mixed>}> */
	private array $logged = [];

	public function setUp(): void {
		parent::setUp();
		$this->store = [PeriodReportService::KEY_ENABLED => true];
		$this->writes = [];
		$this->logged = [];
		$this->now = (new DateTimeImmutable('2026-10-26T06:30:00Z', new DateTimeZone('UTC')))->getTimestamp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueBool')->willReturnCallback(fn (string $app, string $key, bool $default = false): bool => (bool)($this->store[$key] ?? $default));
		$this->appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => (string)($this->store[$key] ?? $default));
		$this->appConfig->method('getValueArray')->willReturnCallback(fn (string $app, string $key, array $default = []): array => (array)($this->store[$key] ?? $default));
		$this->appConfig->method('setValueBool')->willReturnCallback(function (string $app, string $key, bool $value): bool {
			$this->store[$key] = $value;
			$this->writes[] = $key;
			return true;
		});
		$this->appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->store[$key] = $value;
			$this->writes[] = $key;
			return true;
		});
		$this->appConfig->method('setValueArray')->willReturnCallback(function (string $app, string $key, array $value): bool {
			$this->store[$key] = $value;
			$this->writes[] = $key;
			return true;
		});

		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$this->recipients = $this->createMock(PeriodReportRecipientResolver::class);
		$this->builder = $this->createMock(PeriodReportBuilder::class);
		$this->mailer = $this->createMock(PeriodReportMailer::class);

		$this->logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info'] as $level) {
			$this->logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				$this->logged[] = [$level . ': ' . $message, $context];
			});
		}

		$this->service = new PeriodReportService($this->appConfig, $this->time, $this->recipients, $this->builder, $this->mailer, $this->logger);
	}

	private function withRecipients(int $count = 2): void {
		$list = [];
		for ($i = 1; $i <= $count; $i++) {
			$list[] = ['email' => $i === 1 ? self::SECRET_EMAIL : "user{$i}@example.org", 'name' => $i === 1 ? self::SECRET_NAME : "User {$i}"];
		}
		$this->recipients->method('resolve')->willReturn([
			'recipients' => $list,
			'skipped' => ['missingGroups' => 0, 'disabled' => 0, 'noEmail' => 0, 'invalidEmail' => 0, 'duplicates' => 0, 'capped' => false],
		]);
		$this->builder->method('build')->willReturn(['report' => true]);
	}

	private function at(string $iso): void {
		$this->now = (new DateTimeImmutable($iso, new DateTimeZone('UTC')))->getTimestamp();
	}

	public function testDisabledSendsNothingAndResolvesNothing(): void {
		$this->store[PeriodReportService::KEY_ENABLED] = false;
		$this->recipients->expects($this->never())->method('resolve');
		$this->mailer->expects($this->never())->method('send');

		$this->service->run();

		$this->assertSame([], $this->writes);
	}

	public function testSendsToEveryRecipientAndMarksThePeriodAfterSending(): void {
		$this->withRecipients(2);
		$markerSeenDuringSend = [];
		$this->mailer->expects($this->exactly(2))->method('send')->willReturnCallback(
			function () use (&$markerSeenDuringSend): void {
				$markerSeenDuringSend[] = $this->store[PeriodReportService::KEY_LAST_SENT] ?? null;
			},
		);

		$this->service->run();

		$this->assertSame([null, null], $markerSeenDuringSend, 'marker must be written only after sending');
		$this->assertSame('weekly:2026-10-19', $this->store[PeriodReportService::KEY_LAST_SENT]);
	}

	public function testSecondRunAfterSuccessSendsNothing(): void {
		$this->withRecipients(1);
		$this->mailer->expects($this->once())->method('send');

		$this->service->run();
		$this->service->run();
	}

	public function testBeforeTheDueInstantTheEarlierPeriodIsAlreadySent(): void {
		$this->withRecipients(1);
		$this->store[PeriodReportService::KEY_LAST_SENT] = 'weekly:2026-10-12';
		$this->at('2026-10-26T05:59:00Z');
		$this->mailer->expects($this->never())->method('send');

		$this->service->run();
	}

	public function testPeriodDueBeforeEffectiveFromIsNeverSent(): void {
		$this->withRecipients(1);
		// Due instant for weekly:2026-10-19 is 2026-10-26T06:00Z; enabled later that morning.
		$this->store[PeriodReportService::KEY_EFFECTIVE_FROM] = '2026-10-26T06:10:00Z';
		$this->mailer->expects($this->never())->method('send');

		$this->service->run();

		$this->assertArrayNotHasKey(PeriodReportService::KEY_LAST_SENT, $this->store);
	}

	public function testFirstReportAfterEffectiveFromGoesOutAtTheNextDueInstant(): void {
		$this->withRecipients(1);
		$this->store[PeriodReportService::KEY_EFFECTIVE_FROM] = '2026-10-26T06:10:00Z';
		$this->at('2026-11-02T06:00:00Z');
		$this->mailer->expects($this->once())->method('send');

		$this->service->run();

		$this->assertSame('weekly:2026-10-26', $this->store[PeriodReportService::KEY_LAST_SENT]);
	}

	public function testAllFailuresLeaveThePeriodUnmarkedAndTheNextRunRetries(): void {
		$this->withRecipients(2);
		$this->mailer->expects($this->exactly(4))->method('send')->willThrowException(new \RuntimeException('smtp down ' . self::SECRET_EMAIL));

		$this->service->run();
		$this->assertArrayNotHasKey(PeriodReportService::KEY_LAST_SENT, $this->store);

		$this->service->run();
		$this->assertArrayNotHasKey(PeriodReportService::KEY_LAST_SENT, $this->store);
	}

	public function testPartialFailureMarksThePeriodAndDoesNotRetryFailedRecipients(): void {
		$this->withRecipients(3);
		$calls = 0;
		$this->mailer->expects($this->exactly(3))->method('send')->willReturnCallback(function () use (&$calls): void {
			$calls++;
			if ($calls === 2) {
				throw new \RuntimeException('boom');
			}
		});

		$this->service->run();
		$this->service->run();

		$this->assertSame('weekly:2026-10-19', $this->store[PeriodReportService::KEY_LAST_SENT]);
	}

	public function testZeroRecipientsWarnsOncePerPeriodAndDoesNotMark(): void {
		$this->recipients->method('resolve')->willReturn([
			'recipients' => [],
			'skipped' => ['missingGroups' => 0, 'disabled' => 0, 'noEmail' => 0, 'invalidEmail' => 0, 'duplicates' => 0, 'capped' => false],
		]);
		$this->mailer->expects($this->never())->method('send');

		$this->service->run();
		$this->service->run();
		$this->service->run();

		$warnings = array_filter($this->logged, static fn (array $entry): bool => str_starts_with($entry[0], 'warning:'));
		$this->assertCount(1, $warnings);
		$this->assertArrayNotHasKey(PeriodReportService::KEY_LAST_SENT, $this->store);

		// A new period warns again.
		$this->at('2026-11-02T06:00:00Z');
		$this->service->run();
		$warnings = array_filter($this->logged, static fn (array $entry): bool => str_starts_with($entry[0], 'warning:'));
		$this->assertCount(2, $warnings);
	}

	public function testTurningOffAndOnInTheSamePeriodAfterASuccessDoesNotResend(): void {
		$this->withRecipients(1);
		$this->mailer->expects($this->once())->method('send');

		$this->service->run();
		$this->store[PeriodReportService::KEY_ENABLED] = false;
		$this->service->run();
		$this->store[PeriodReportService::KEY_ENABLED] = true;
		$this->service->run();
	}

	public function testToggleIsReadOnEveryRun(): void {
		$this->withRecipients(1);
		$this->store[PeriodReportService::KEY_ENABLED] = false;
		$this->mailer->expects($this->once())->method('send');

		$this->service->run();
		$this->store[PeriodReportService::KEY_ENABLED] = true;
		$this->service->run();
	}

	public function testNewPeriodSends(): void {
		$this->withRecipients(1);
		$this->mailer->expects($this->exactly(2))->method('send');

		$this->service->run();
		$this->at('2026-11-02T06:00:00Z');
		$this->service->run();

		$this->assertSame('weekly:2026-10-26', $this->store[PeriodReportService::KEY_LAST_SENT]);
	}

	public function testScheduleChangeUsesTheNewFrequencyKey(): void {
		$this->withRecipients(1);
		$this->mailer->expects($this->exactly(2))->method('send');

		$this->service->run();
		$this->store[PeriodReportService::KEY_SCHEDULE] = ['frequency' => 'daily', 'time' => '09:00', 'timezone' => 'Africa/Nairobi'];
		$this->service->run();

		$this->assertSame('daily:2026-10-25', $this->store[PeriodReportService::KEY_LAST_SENT]);
	}

	public function testBuilderErrorDoesNotMarkAndDoesNotSend(): void {
		$this->recipients->method('resolve')->willReturn([
			'recipients' => [['email' => self::SECRET_EMAIL, 'name' => self::SECRET_NAME]],
			'skipped' => ['missingGroups' => 0, 'disabled' => 0, 'noEmail' => 0, 'invalidEmail' => 0, 'duplicates' => 0, 'capped' => false],
		]);
		$this->builder->method('build')->willThrowException(new \RuntimeException('db said ' . self::SECRET_EMAIL));
		$this->mailer->expects($this->never())->method('send');

		$this->service->run();

		$this->assertArrayNotHasKey(PeriodReportService::KEY_LAST_SENT, $this->store);
	}

	public function testLogsContainNoAddressesNamesOrExceptionMessages(): void {
		$this->withRecipients(2);
		$this->mailer->method('send')->willThrowException(new \RuntimeException('smtp rejected ' . self::SECRET_EMAIL));

		$this->service->run();

		$this->assertNotSame([], $this->logged);
		$dump = json_encode($this->logged);
		$this->assertStringNotContainsString(self::SECRET_EMAIL, $dump);
		$this->assertStringNotContainsString(self::SECRET_NAME, $dump);
		$this->assertStringNotContainsString('smtp rejected', $dump);
		$this->assertStringNotContainsString('user2@example.org', $dump);
	}

	public function testMarkerWriteFailureIsLoggedAsError(): void {
		$this->withRecipients(1);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(true);
		$appConfig->method('getValueString')->willReturn('');
		$appConfig->method('getValueArray')->willReturn([]);
		$appConfig->method('setValueString')->willThrowException(new \RuntimeException('write failed'));
		$service = new PeriodReportService($appConfig, $this->time, $this->recipients, $this->builder, $this->mailer, $this->logger);

		$service->run();

		$errors = array_filter($this->logged, static fn (array $entry): bool => str_starts_with($entry[0], 'error:'));
		$this->assertCount(1, $errors);
	}

	public function testNextRunIsNullWhileDisabledAndServerComputedWhileEnabled(): void {
		$this->store[PeriodReportService::KEY_ENABLED] = false;
		$this->assertNull($this->service->nextRun());

		$this->store[PeriodReportService::KEY_ENABLED] = true;
		$next = $this->service->nextRun();

		$this->assertSame([
			'dueAt' => '2026-11-02T06:00:00Z',
			'periodFrom' => '2026-10-26',
			'periodTo' => '2026-11-01',
			'timezone' => 'Africa/Nairobi',
		], $next);
	}

	public function testSaveConfigEnablingMovesEffectiveFromAndWritesOnlyItsOwnKeys(): void {
		$this->store[PeriodReportService::KEY_ENABLED] = false;

		$this->service->saveConfig(true, ['frequency' => 'daily', 'time' => '08:00']);

		$this->assertSame('2026-10-26T06:30:00Z', $this->store[PeriodReportService::KEY_EFFECTIVE_FROM]);
		$this->assertTrue($this->store[PeriodReportService::KEY_ENABLED]);
		$this->assertSame('daily', $this->store[PeriodReportService::KEY_SCHEDULE]['frequency']);
		$allowed = [PeriodReportService::KEY_ENABLED, PeriodReportService::KEY_SCHEDULE, PeriodReportService::KEY_EFFECTIVE_FROM];
		$this->assertSame([], array_values(array_diff($this->writes, $allowed)));
		$this->assertArrayNotHasKey('operations_dashboard_enabled', $this->store);
		$this->assertArrayNotHasKey('operations_groups', $this->store);
	}

	public function testSaveConfigIdenticalSaveDoesNotMoveEffectiveFrom(): void {
		$this->store[PeriodReportService::KEY_SCHEDULE] = ['frequency' => 'weekly', 'weekday' => 1, 'dayOfMonth' => 1, 'time' => '09:00', 'timezone' => 'Africa/Nairobi'];
		$this->store[PeriodReportService::KEY_EFFECTIVE_FROM] = '2026-01-01T00:00:00Z';

		$this->service->saveConfig(true, ['frequency' => 'weekly', 'weekday' => 1, 'dayOfMonth' => 1, 'time' => '09:00', 'timezone' => 'Africa/Nairobi']);

		$this->assertSame('2026-01-01T00:00:00Z', $this->store[PeriodReportService::KEY_EFFECTIVE_FROM]);
	}

	public function testSaveConfigScheduleChangeWhileEnabledMovesEffectiveFrom(): void {
		$this->store[PeriodReportService::KEY_EFFECTIVE_FROM] = '2026-01-01T00:00:00Z';

		$this->service->saveConfig(true, ['frequency' => 'monthly', 'dayOfMonth' => 5]);

		$this->assertSame('2026-10-26T06:30:00Z', $this->store[PeriodReportService::KEY_EFFECTIVE_FROM]);
	}

	public function testSaveConfigDisablingDoesNotMoveEffectiveFrom(): void {
		$this->store[PeriodReportService::KEY_EFFECTIVE_FROM] = '2026-01-01T00:00:00Z';

		$this->service->saveConfig(false, ['frequency' => 'daily']);

		$this->assertSame('2026-01-01T00:00:00Z', $this->store[PeriodReportService::KEY_EFFECTIVE_FROM]);
		$this->assertFalse($this->store[PeriodReportService::KEY_ENABLED]);
	}

	public function testSaveConfigEmptyScheduleKeepsTheStoredSchedule(): void {
		$stored = ['frequency' => 'monthly', 'weekday' => 1, 'dayOfMonth' => 9, 'time' => '07:00', 'timezone' => 'Europe/Berlin'];
		$this->store[PeriodReportService::KEY_SCHEDULE] = $stored;
		$this->store[PeriodReportService::KEY_EFFECTIVE_FROM] = '2026-01-01T00:00:00Z';

		$this->service->saveConfig(true, []);

		$this->assertSame($stored, $this->store[PeriodReportService::KEY_SCHEDULE]);
		$this->assertSame('2026-01-01T00:00:00Z', $this->store[PeriodReportService::KEY_EFFECTIVE_FROM]);
	}

	public function testSaveConfigInvalidScheduleThrowsAndWritesNothing(): void {
		$this->expectException(InvalidArgumentException::class);
		try {
			$this->service->saveConfig(true, ['frequency' => 'hourly']);
		} finally {
			$this->assertSame([], $this->writes);
		}
	}

	public function testSendTestSendsOneReportToOneAddressWithoutTouchingState(): void {
		$this->store = [];
		$this->mailer->method('isValidAddress')->willReturn(true);
		$this->builder->method('build')->willReturn(['report' => true]);
		$this->recipients->expects($this->never())->method('resolve');
		$this->mailer->expects($this->once())->method('send')->with(['report' => true], 'admin@example.org', 'Admin', true);

		$this->service->sendTest('admin@example.org', 'Admin');

		$this->assertSame([], $this->writes);
	}

	public function testSendTestRejectsAnInvalidAddressBeforeBuildingAnything(): void {
		$this->mailer->method('isValidAddress')->willReturn(false);
		$this->builder->expects($this->never())->method('build');
		$this->mailer->expects($this->never())->method('send');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->sendTest('not-an-address', 'Admin');
	}

	public function testSendTestPropagatesTransportFailureSoTheAdminSeesIt(): void {
		$this->mailer->method('isValidAddress')->willReturn(true);
		$this->builder->method('build')->willReturn(['report' => true]);
		$this->mailer->method('send')->willThrowException(new \RuntimeException('smtp down'));

		$this->expectException(\RuntimeException::class);
		$this->service->sendTest('admin@example.org', 'Admin');
	}
}
