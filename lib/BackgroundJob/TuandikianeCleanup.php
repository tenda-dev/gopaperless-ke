<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\BackgroundJob;

use OCA\Libresign\Db\TuandikianeDraftMapper;
use OCA\Libresign\Service\TuandikianeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Every hour, deletes the Tuandikiane drafts nobody sent before they expired:
 * the file, its sign requests and boxes, and the row. A sent draft is the
 * sender's and is never touched.
 */
class TuandikianeCleanup extends TimedJob {
	private const BATCH = 50;

	public function __construct(
		ITimeFactory $time,
		private TuandikianeDraftMapper $drafts,
		private TuandikianeService $tuandikiane,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	#[\Override]
	protected function run($argument): void {
		foreach ($this->drafts->findExpiredDrafts($this->time->getDateTime(), self::BATCH) as $draft) {
			try {
				$this->tuandikiane->deleteDraft($draft);
			} catch (\Throwable $e) {
				$this->logger->warning('An expired Tuandikiane draft could not be deleted; the next run tries again', [
					'draft' => $draft->getDraftUuid(),
					'exception' => $e,
				]);
			}
		}
	}
}
