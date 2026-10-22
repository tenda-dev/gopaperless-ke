<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\BackgroundJob;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\PublicWorkflow;
use OCA\Libresign\Db\PublicWorkflowMapper;
use OCA\Libresign\Service\AccountService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Purge expired anonymous public workflows with everything they own.
 *
 * A workflow past its expiry can no longer be accessed, but nothing else
 * removed it: the ACTIVE row, the temporary Nextcloud account and the DRAFT
 * document it owned all persisted forever. Deleting the temporary user
 * cascades their files (the prepared DRAFT among them), so each purge is:
 * delete the temp owner, then the workflow row. A row whose owner cannot be
 * deleted is kept for the next run rather than orphaned.
 */
class PublicWorkflowCleanup extends TimedJob {
	private const BATCH = 50;

	public function __construct(
		ITimeFactory $time,
		IAppConfig $appConfig,
		private PublicWorkflowMapper $mapper,
		private IUserManager $userManager,
		private AccountService $accountService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		// Default every 30 minutes; admin-configurable via
		// public_workflow_cleanup_interval_minutes, floored at 1 minute. The batch
		// bound keeps each run cheap.
		$minutes = 30;
		try {
			$minutes = max(1, $appConfig->getValueInt(Application::APP_ID, 'public_workflow_cleanup_interval_minutes', 30));
		} catch (\Throwable) {
		}
		$this->setInterval($minutes * 60);
	}

	protected function run($argument): void {
		$expired = $this->mapper->findExpiredActive(self::BATCH);
		foreach ($expired as $workflow) {
			try {
				$this->purge($workflow);
			} catch (\Throwable $e) {
				$this->logger->warning('[PublicWorkflowCleanup] purge deferred; will retry', [
					'workflow_id' => $workflow->getId(),
					'exception' => $e,
				]);
			}
		}
	}

	private function purge(PublicWorkflow $workflow): void {
		$owner = $this->userManager->get($workflow->getOwnerUid());
		if ($owner !== null) {
			if (!$this->accountService->isTemporaryUser($owner)) {
				// An ACTIVE row must still belong to its temp owner; anything
				// else means the workflow was claimed mid-purge. Keep the row
				// (the claim path cleans it up) but never touch a real account.
				$this->logger->warning('[PublicWorkflowCleanup] expired workflow owner is not a temporary user; leaving both intact', [
					'workflow_id' => $workflow->getId(),
					'owner_uid' => $owner->getUID(),
				]);
				return;
			}
			$this->accountService->deleteTemporaryUser($owner);
		}
		// Deleting the user cascades their files; the DRAFT goes with them.
		$this->mapper->delete($workflow);
	}
}
