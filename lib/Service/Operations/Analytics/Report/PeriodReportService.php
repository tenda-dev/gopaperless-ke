<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Operations\Analytics\Report;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Libresign\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the configurable period email report.
 *
 * The background job wakes every 15 minutes and sends the latest due, unsent
 * period. Delivery time is the first cron run at or after the configured day
 * and time in the configured timezone; a late run still reports the correct
 * (calendar-aligned) period.
 *
 * Delivery guarantees:
 *  - At-least-once, not exactly-once: a crash after some sends but before the
 *    "sent" marker is written may duplicate the report on retry.
 *  - Partial delivery marks the period sent when at least one recipient
 *    succeeded; failed recipients are not retried afterwards.
 *  - If no recipient succeeded the period is not marked and is retried on the
 *    next run until a newer period becomes due.
 *  - Missed periods (cron down for longer than a cycle) are not back-filled.
 *
 * Privacy: logs carry counts and period keys only. Never email addresses,
 * user ids (often emails in this app), names, metric values or exception
 * messages (SMTP errors can echo addresses).
 */
class PeriodReportService {
	public const KEY_ENABLED = 'operations_email_reports_enabled';
	public const KEY_SCHEDULE = 'operations_email_schedule';
	public const KEY_EFFECTIVE_FROM = 'operations_email_effective_from';
	public const KEY_LAST_SENT = 'operations_email_last_sent_period';
	public const KEY_LAST_WARNED = 'operations_email_last_warned_period';

	private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s\Z';

	public function __construct(
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private PeriodReportRecipientResolver $recipients,
		private PeriodReportBuilder $builder,
		private PeriodReportMailer $mailer,
		private LoggerInterface $logger,
	) {
	}

	public function isEnabled(): bool {
		try {
			return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_ENABLED, false);
		} catch (AppConfigTypeConflictException) {
			return false;
		}
	}

	/** Effective schedule: stored values with defaults for anything missing or invalid. */
	public function getSchedule(): PeriodSchedule {
		try {
			$stored = $this->appConfig->getValueArray(Application::APP_ID, self::KEY_SCHEDULE, []);
		} catch (AppConfigTypeConflictException) {
			$stored = [];
		}
		return PeriodSchedule::fromStoredOrDefault($stored);
	}

	public function run(): void {
		// Read on every run so toggling takes effect without a restart.
		if (!$this->isEnabled()) {
			return;
		}

		$now = $this->now();
		$schedule = $this->getSchedule();
		$window = PeriodWindow::latestDue($schedule, $now);

		$effectiveFrom = $this->effectiveFrom();
		if ($effectiveFrom !== null && $window->dueAt < $effectiveFrom) {
			return;
		}
		if ($this->readString(self::KEY_LAST_SENT) === $window->key()) {
			return;
		}

		$resolved = $this->recipients->resolve();
		if ($resolved['recipients'] === []) {
			if ($this->readString(self::KEY_LAST_WARNED) !== $window->key()) {
				$this->logger->warning('Operations period report has no recipients; check the Operations dashboard groups', [
					'period' => $window->key(),
					'skipped' => $resolved['skipped'],
				]);
				try {
					$this->appConfig->setValueString(Application::APP_ID, self::KEY_LAST_WARNED, $window->key());
				} catch (\Throwable $e) {
					// Only de-duplicates the warning; a repeat every run is the worst case.
					$this->logger->error('Operations period report could not record the no-recipients warning', [
						'period' => $window->key(),
						'exception' => get_class($e),
					]);
				}
			}
			return;
		}

		try {
			$report = $this->builder->build($window);
		} catch (\Throwable $e) {
			$this->logger->error('Operations period report could not be built', [
				'period' => $window->key(),
				'exception' => get_class($e),
			]);
			return;
		}

		$delivered = 0;
		$failed = 0;
		foreach ($resolved['recipients'] as $recipient) {
			try {
				$this->mailer->send($report, $recipient['email'], $recipient['name']);
				$delivered++;
			} catch (\Throwable) {
				$failed++;
			}
		}

		if ($delivered >= 1) {
			// Marker is written only AFTER sending.
			try {
				$this->appConfig->setValueString(Application::APP_ID, self::KEY_LAST_SENT, $window->key());
			} catch (\Throwable $e) {
				$this->logger->error('Operations period report sent but the sent marker could not be written; it may be sent again', [
					'period' => $window->key(),
					'exception' => get_class($e),
				]);
			}
		}

		$this->logger->info('Operations period report run finished', [
			'period' => $window->key(),
			'delivered' => $delivered,
			'failed' => $failed,
			'marked' => $delivered >= 1,
			'skipped' => $resolved['skipped'],
		]);
	}

	/**
	 * Sends one report, for the latest due period, to a single address so an
	 * admin can verify mail delivery. Works whether or not reporting is
	 * enabled, uses the stored schedule, and has no side effects: it never
	 * writes the sent marker, never reads recipients and never sends to anyone
	 * else.
	 *
	 * @throws InvalidArgumentException when the address is not valid
	 * @throws \Throwable when the report cannot be built or sent
	 */
	public function sendTest(string $email, string $name): void {
		if (!$this->mailer->isValidAddress($email)) {
			throw new InvalidArgumentException('Invalid email address');
		}
		$window = PeriodWindow::latestDue($this->getSchedule(), $this->now());
		$this->mailer->send($this->builder->build($window), $email, $name !== '' ? $name : $email, true);
	}

	/**
	 * Next scheduled report, for the settings UI. Null while disabled.
	 *
	 * @return array{dueAt: string, periodFrom: string, periodTo: string, timezone: string}|null
	 */
	public function nextRun(): ?array {
		if (!$this->isEnabled()) {
			return null;
		}
		$schedule = $this->getSchedule();
		$window = PeriodWindow::nextDue($schedule, $this->now());
		return [
			'dueAt' => $window->dueAt->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP_FORMAT),
			'periodFrom' => $window->current->from,
			'periodTo' => $window->current->to,
			'timezone' => $schedule->timezone,
		];
	}

	/**
	 * Persists the admin settings.
	 *
	 * `effective_from` is moved to "now" only when the report becomes enabled
	 * or the schedule changes while enabled, so changing settings never emits
	 * a report for a period that fell due earlier. An empty $schedule keeps
	 * the stored (or default) schedule. Only the report's own keys are
	 * written; the dashboard switch and groups are never touched.
	 *
	 * @param array<array-key, mixed> $schedule
	 * @throws InvalidArgumentException before anything is written
	 */
	public function saveConfig(bool $enabled, array $schedule): void {
		$previousEnabled = $this->isEnabled();
		$previousSchedule = $this->getSchedule();
		$newSchedule = $schedule === [] ? $previousSchedule : PeriodSchedule::fromArray($schedule);

		$moveEffectiveFrom = $enabled && (!$previousEnabled || $newSchedule->toArray() !== $previousSchedule->toArray());

		// Order matters: effective_from first, the enable switch last. A failure
		// part-way can then only suppress a catch-up send, never allow one.
		if ($moveEffectiveFrom) {
			$this->appConfig->setValueString(Application::APP_ID, self::KEY_EFFECTIVE_FROM, $this->now()->format(self::TIMESTAMP_FORMAT));
		}
		$this->appConfig->setValueArray(Application::APP_ID, self::KEY_SCHEDULE, $newSchedule->toArray());
		$this->appConfig->setValueBool(Application::APP_ID, self::KEY_ENABLED, $enabled);
	}

	private function now(): DateTimeImmutable {
		return (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
	}

	private function effectiveFrom(): ?DateTimeImmutable {
		$value = $this->readString(self::KEY_EFFECTIVE_FROM);
		if ($value === '') {
			return null;
		}
		$parsed = DateTimeImmutable::createFromFormat(self::TIMESTAMP_FORMAT, $value, new DateTimeZone('UTC'));
		return $parsed === false ? null : $parsed;
	}

	private function readString(string $key): string {
		try {
			return $this->appConfig->getValueString(Application::APP_ID, $key, '');
		} catch (AppConfigTypeConflictException) {
			return '';
		}
	}
}
