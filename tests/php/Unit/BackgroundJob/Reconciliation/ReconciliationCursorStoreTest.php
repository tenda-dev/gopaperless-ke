<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\BackgroundJob\Reconciliation;

use OCA\Libresign\BackgroundJob\Reconciliation\CompositeCursor;
use OCA\Libresign\BackgroundJob\Reconciliation\ReconciliationCursorStore;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;

final class ReconciliationCursorStoreTest extends \OCA\Libresign\Tests\Unit\TestCase {
	private IAppConfig&MockObject $appConfig;
	private ReconciliationCursorStore $store;

	/** @var array<string, string> in-memory backing for the mocked app config */
	private array $backing = [];

	public function setUp(): void {
		parent::setUp();
		$this->backing = [];
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->appConfig->method('getValueString')
			->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->backing[$key] ?? $default);
		$this->appConfig->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value): bool {
				$this->backing[$key] = $value;
				return true;
			});

		$this->store = new ReconciliationCursorStore($this->appConfig);
	}

	public function testForwardCheckpointDefaultsToBottomOfOrder(): void {
		$checkpoint = $this->store->getForwardCheckpoint();
		$this->assertSame(0, $checkpoint->signed);
		$this->assertSame(0, $checkpoint->id);
	}

	public function testForwardCheckpointRoundTripsBothComponents(): void {
		$this->store->setForwardCheckpoint(new CompositeCursor(1700000000, 4242));

		$checkpoint = $this->store->getForwardCheckpoint();
		$this->assertSame(1700000000, $checkpoint->signed);
		$this->assertSame(4242, $checkpoint->id);
	}

	public function testWindowCursorIsNullUntilSet(): void {
		$this->assertNull($this->store->getWindowCursor());
	}

	public function testWindowCursorRoundTripsAndClearsToNull(): void {
		$this->store->setWindowCursor(new CompositeCursor(1700000500, 99));
		$cursor = $this->store->getWindowCursor();
		$this->assertNotNull($cursor);
		$this->assertSame(1700000500, $cursor->signed);
		$this->assertSame(99, $cursor->id);

		$this->store->setWindowCursor(null);
		$this->assertNull($this->store->getWindowCursor());
	}

	public function testRecoveryCursorRoundTripsAndClearsToNull(): void {
		$this->assertNull($this->store->getRecoveryCursor());

		$this->store->setRecoveryCursor(new CompositeCursor(123, 7));
		$cursor = $this->store->getRecoveryCursor();
		$this->assertNotNull($cursor);
		$this->assertSame(123, $cursor->signed);
		$this->assertSame(7, $cursor->id);

		$this->store->setRecoveryCursor(null);
		$this->assertNull($this->store->getRecoveryCursor());
	}

	public function testRecoverySweepDefaultsToGenerationZeroNeverCompleted(): void {
		$sweep = $this->store->getRecoverySweep();
		$this->assertSame(0, $sweep['generation']);
		$this->assertNull($sweep['completedAt']);
	}

	public function testRecoverySweepRoundTripsLifecycle(): void {
		$this->store->setRecoverySweep(3, 1700000000, null);
		$inProgress = $this->store->getRecoverySweep();
		$this->assertSame(3, $inProgress['generation']);
		$this->assertSame(1700000000, $inProgress['startedAt']);
		$this->assertNull($inProgress['completedAt'], 'an in-progress sweep must be distinguishable from a completed one');

		$this->store->setRecoverySweep(4, 1700000000, 1700000600);
		$completed = $this->store->getRecoverySweep();
		$this->assertSame(4, $completed['generation']);
		$this->assertSame(1700000600, $completed['completedAt']);
	}

	public function testMalformedPersistedCursorIsTreatedAsUnset(): void {
		$this->backing['reconcile_window_cursor'] = '{"signed":123}'; // missing id
		$this->assertNull($this->store->getWindowCursor());
	}
}
