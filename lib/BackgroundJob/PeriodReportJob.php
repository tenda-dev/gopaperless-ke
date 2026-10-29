<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\BackgroundJob;

use OCA\Libresign\Service\Operations\Analytics\Report\PeriodReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Wakes every 15 minutes and lets PeriodReportService send the latest due,
 * unsent Operations period report. Exits immediately when the report is
 * disabled or nothing is due. A TimedJob can only guarantee "at least N
 * seconds since the last run", so delivery is the first cron run at or after
 * the configured time; working Nextcloud cron is required.
 */
class PeriodReportJob extends TimedJob {
	public const INTERVAL_SECONDS = 900;

	public function __construct(
		ITimeFactory $time,
		protected PeriodReportService $periodReportService,
		protected LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function run($argument): void {
		try {
			$this->periodReportService->run();
		} catch (\Throwable $e) {
			$this->logger->error('Operations period report job failed', [
				'exception' => get_class($e),
			]);
		}
	}
}
