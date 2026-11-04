<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Identify;

use OCA\Libresign\Collaboration\Collaborators\SignerPlugin;
use OCP\Share\IShare;

/**
 * Deterministic, stable ranking applied after reconciliation and before
 * formatting. It only reorders; it never adds, drops, or merges items, and it
 * does not touch unify()/scoreItem(), plugin pagination, or phone ordering.
 *
 * Tiers (lower first):
 *   0  discoverable account rows        (method === 'account')
 *   1  historical email signers         (shareType 50, method 'email')
 *   2  genuine external email signers   (shareType TYPE_EMAIL)
 *   3  phone / everything else
 *
 * Intra-tier order is the incoming order (which already carries exact-before-wide
 * from unify), except tier 0, where an email-resolved account precedes a
 * uid-only-resolved account for a split identity. Ties fall back to the original
 * index, so identical requests always produce identical ordering.
 */
class RankingStrategy {
	/**
	 * @param list<array> $items
	 * @return list<array>
	 */
	public function rankItems(array $items): array {
		$decorated = [];
		foreach ($items as $index => $item) {
			$decorated[] = [
				'tier' => $this->tier($item),
				'subRank' => $this->subRank($item),
				'index' => $index,
				'item' => $item,
			];
		}

		usort($decorated, static function (array $a, array $b): int {
			return [$a['tier'], $a['subRank'], $a['index']]
				<=> [$b['tier'], $b['subRank'], $b['index']];
		});

		return array_map(static fn (array $d): array => $d['item'], $decorated);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function tier(array $item): int {
		$method = (string)($item['method'] ?? '');
		$shareType = $item['value']['shareType'] ?? null;

		if ($method === 'account') {
			return 0;
		}
		if ($shareType === SignerPlugin::TYPE_SIGNER && $method === 'email') {
			return 1;
		}
		if ($shareType === IShare::TYPE_EMAIL) {
			return 2;
		}
		return 3;
	}

	/**
	 * Tier-0 sub-order: an account reached via the email path ranks before a
	 * uid-only account (for the split email/uid identity). Accounts without
	 * provenance (e.g. EmailAccountPlugin rows) are treated as email-path.
	 *
	 * @param array<string, mixed> $item
	 */
	private function subRank(array $item): int {
		if (($item['method'] ?? null) !== 'account') {
			return 0;
		}
		$via = $item['_via'] ?? [];
		if ($via === [] || in_array('email', $via, true)) {
			return 0;
		}
		return 1;
	}
}
