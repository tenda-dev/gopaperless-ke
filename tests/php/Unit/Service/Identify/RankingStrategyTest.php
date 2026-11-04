<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Identify;

use OCA\Libresign\Collaboration\Collaborators\EmailAccountPlugin;
use OCA\Libresign\Collaboration\Collaborators\SignerPlugin;
use OCA\Libresign\Service\Identify\RankingStrategy;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

class RankingStrategyTest extends TestCase {
	private function account(string $uid, array $via = []): array {
		$row = [
			'label' => 'DN ' . $uid,
			'shareWithDisplayNameUnique' => $uid . '@x',
			'method' => 'account',
			'value' => ['shareType' => EmailAccountPlugin::TYPE_SIGNER_EMAIL_ACCOUNT, 'shareWith' => $uid],
		];
		if ($via !== []) {
			$row['_via'] = $via;
		}
		return $row;
	}

	private function historical(string $email): array {
		return [
			'label' => 'Historic ' . $email,
			'shareWithDisplayNameUnique' => $email,
			'method' => 'email',
			'value' => ['shareType' => SignerPlugin::TYPE_SIGNER, 'shareWith' => $email],
		];
	}

	private function external(string $email): array {
		return [
			'label' => $email,
			'shareWithDisplayNameUnique' => $email,
			'method' => 'email',
			'value' => ['shareType' => IShare::TYPE_EMAIL, 'shareWith' => $email],
		];
	}

	private function phone(string $value): array {
		return [
			'label' => $value,
			'shareWithDisplayNameUnique' => $value,
			'method' => 'sms',
			'value' => ['shareType' => 51, 'shareWith' => $value],
		];
	}

	private function shareTypes(array $ranked): array {
		return array_map(static fn (array $i): int => $i['value']['shareType'], $ranked);
	}

	public function testTierOrderAccountHistoricalExternalPhone(): void {
		$ranking = new RankingStrategy();
		$ranked = $ranking->rankItems([
			$this->external('x@x'),
			$this->historical('h@x'),
			$this->account('a'),
			$this->phone('+1'),
		]);
		$this->assertSame(
			[EmailAccountPlugin::TYPE_SIGNER_EMAIL_ACCOUNT, SignerPlugin::TYPE_SIGNER, IShare::TYPE_EMAIL, 51],
			$this->shareTypes($ranked),
		);
	}

	public function testSplitAccountEmailPathBeforeUidPath(): void {
		$ranking = new RankingStrategy();
		$ranked = $ranking->rankItems([
			$this->account('b', ['uid']),
			$this->account('a', ['email']),
			$this->historical('h@x'),
		]);
		$this->assertSame('a', $ranked[0]['value']['shareWith']);
		$this->assertSame('b', $ranked[1]['value']['shareWith']);
		$this->assertSame(SignerPlugin::TYPE_SIGNER, $ranked[2]['value']['shareType']);
	}

	public function testExactBeforeWidePreservedWithinTier(): void {
		// Incoming order is the only intra-tier signal (besides the via sub-rank);
		// ranking must preserve it for same-tier items.
		$ranking = new RankingStrategy();
		$ranked = $ranking->rankItems([
			$this->account('first'),
			$this->account('second'),
		]);
		$this->assertSame('first', $ranked[0]['value']['shareWith']);
		$this->assertSame('second', $ranked[1]['value']['shareWith']);
	}

	public function testDeterministicAcrossIdenticalRequests(): void {
		$ranking = new RankingStrategy();
		$input = [
			$this->external('x@x'),
			$this->historical('h@x'),
			$this->account('a'),
			$this->phone('+1'),
		];
		$this->assertSame($ranking->rankItems($input), $ranking->rankItems($input));
	}

	public function testNoItemLostOrDuplicated(): void {
		$ranking = new RankingStrategy();
		$input = [
			$this->external('x@x'),
			$this->account('a'),
			$this->account('b', ['uid']),
			$this->phone('+1'),
			$this->historical('h@x'),
		];
		$ranked = $ranking->rankItems($input);
		$this->assertCount(count($input), $ranked);
		$this->assertEqualsCanonicalizing($input, $ranked);
	}

	public function testAccountWithoutViaTreatedAsEmailPath(): void {
		$ranking = new RankingStrategy();
		$ranked = $ranking->rankItems([
			$this->account('b', ['uid']),
			$this->account('a'),
		]);
		$this->assertSame('a', $ranked[0]['value']['shareWith']);
	}
}
