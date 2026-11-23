<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\BackgroundJob\Reconciliation;

use OCA\Libresign\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Persists reconciliation progress and recovery state in app config.
 *
 * Each cursor is stored as a single JSON value so its timestamp and ID
 * components remain atomic. Forward progress, window progress, and recovery
 * sweep state are maintained independently to prevent one recovery mechanism
 * from invalidating another.
 *
 * Cursor state controls scan efficiency and recovery coverage, not entitlement
 * consumption safety. SigningSettlementService::settle() remains the authority
 * for idempotent settlement through its transactional consumed marker.
 */
class ReconciliationCursorStore {
	private const KEY_FORWARD_CHECKPOINT = 'reconcile_forward_checkpoint';
	private const KEY_WINDOW_CURSOR = 'reconcile_window_cursor';
	private const KEY_RECOVERY_CURSOR = 'reconcile_recovery_cursor';
	private const KEY_RECOVERY_SWEEP = 'reconcile_recovery_sweep';

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function getForwardCheckpoint(): CompositeCursor {
		// Start from the beginning when no checkpoint exists, ensuring the initial
		// scan cannot skip previously created signing requests.
		return $this->readCursor(self::KEY_FORWARD_CHECKPOINT) ?? new CompositeCursor(0, 0);
	}

	public function setForwardCheckpoint(CompositeCursor $cursor): void {
		$this->writeCursor(self::KEY_FORWARD_CHECKPOINT, $cursor);
	}

	public function getWindowCursor(): ?CompositeCursor {
		return $this->readCursor(self::KEY_WINDOW_CURSOR);
	}

	public function setWindowCursor(?CompositeCursor $cursor): void {
		$this->writeCursor(self::KEY_WINDOW_CURSOR, $cursor);
	}

	public function getRecoveryCursor(): ?CompositeCursor {
		return $this->readCursor(self::KEY_RECOVERY_CURSOR);
	}

	public function setRecoveryCursor(?CompositeCursor $cursor): void {
		$this->writeCursor(self::KEY_RECOVERY_CURSOR, $cursor);
	}

	/**
	 * @return array{generation: int, startedAt: int, completedAt: int|null}
	 */
	public function getRecoverySweep(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::KEY_RECOVERY_SWEEP, '');
		$decoded = $raw === '' ? null : json_decode($raw, true);

		if (!is_array($decoded) || !isset($decoded['generation'], $decoded['startedAt'])) {
			return ['generation' => 0, 'startedAt' => 0, 'completedAt' => null];
		}

		return [
			'generation' => (int)$decoded['generation'],
			'startedAt' => (int)$decoded['startedAt'],
			'completedAt' => isset($decoded['completedAt']) ? (int)$decoded['completedAt'] : null,
		];
	}

	public function setRecoverySweep(int $generation, int $startedAt, ?int $completedAt): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_RECOVERY_SWEEP, (string)json_encode([
			'generation' => $generation,
			'startedAt' => $startedAt,
			'completedAt' => $completedAt,
		]));
	}

	private function readCursor(string $key): ?CompositeCursor {
		$raw = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		if ($raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded) || !isset($decoded['signed'], $decoded['id'])) {
			return null;
		}

		return new CompositeCursor((int)$decoded['signed'], (int)$decoded['id']);
	}

	private function writeCursor(string $key, ?CompositeCursor $cursor): void {
		$value = $cursor === null
			? ''
			: (string)json_encode(['signed' => $cursor->signed, 'id' => $cursor->id]);

		$this->appConfig->setValueString(Application::APP_ID, $key, $value);
	}
}
