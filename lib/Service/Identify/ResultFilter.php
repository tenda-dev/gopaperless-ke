<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Identify;

use OCP\Share\IShare;

class ResultFilter {
	public function unify(array $list): array {
		$ids = [];
		$scores = [];
		$return = [];
		foreach ($list as $items) {
			foreach ($items as $item) {
				$shareWith = $item['value']['shareWith'] ?? null;
				if ($shareWith === null) {
					continue;
				}
				$score = $this->scoreItem($item);
				$existingIndex = array_search($shareWith, $ids, true);
				if ($existingIndex !== false) {
					if ($score >= ($scores[$existingIndex] ?? -1)) {
						$return[$existingIndex] = $item;
						$scores[$existingIndex] = $score;
					}
					continue;
				}
				$ids[] = $shareWith;
				$scores[] = $score;
				$return[] = $item;
			}
		}
		return $return;
	}

	private function scoreItem(array $item): int {
		$label = (string)($item['label'] ?? '');
		$unique = (string)($item['shareWithDisplayNameUnique'] ?? '');
		$shareWith = (string)($item['value']['shareWith'] ?? '');

		if ($label === '') {
			return 0;
		}
		if ($label !== $unique && $label !== $shareWith) {
			return 2;
		}
		return 1;
	}

	public function excludeEmpty(array $list): array {
		return array_filter($list, fn ($result) => strlen((string)($result['value']['shareWith'] ?? '')) > 0);
	}

	public function excludeNotAllowed(array $list): array {
		return array_filter($list, fn ($result) => isset($result['method']) && !empty($result['method']));
	}

	/**
	 * Keep core account results only when they match the account identifier
	 * (UID or display name). This prevents the account search from returning
	 * users that only happen to have the search string as their profile email.
	 *
	 * Results produced by custom plugins (e.g. phone-to-account or
	 * email-to-account) are preserved because they already carry an explicit
	 * method.
	 */
	public function filterAccountMatches(array $list, string $search): array {
		$searchLower = strtolower($search);
		return array_values(array_filter($list, function (array $item) use ($searchLower): bool {
			if (($item['value']['shareType'] ?? null) !== IShare::TYPE_USER) {
				return true;
			}
			if (!empty($item['method'])) {
				return true;
			}
			$uid = strtolower((string)($item['value']['shareWith'] ?? ''));
			$label = strtolower((string)($item['label'] ?? ''));
			return str_contains($uid, $searchLower) || str_contains($label, $searchLower);
		}));
	}
}
