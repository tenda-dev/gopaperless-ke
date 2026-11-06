<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Identify;

use OCA\Libresign\Collaboration\Collaborators\AccountPhonePlugin;
use OCA\Libresign\Collaboration\Collaborators\ContactPhonePlugin;
use OCA\Libresign\Collaboration\Collaborators\EmailAccountPlugin;
use OCA\Libresign\Collaboration\Collaborators\ManualPhonePlugin;
use OCA\Libresign\Collaboration\Collaborators\SignerPlugin;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Reconciles email identities in the unified signer-search result against
 * existing internal accounts, deciding per item whether to upgrade to an account
 * row, preserve a historical email signer, keep a genuine external suggestion, or
 * suppress an external suggestion that resolves to a non-discoverable account.
 *
 * Contracts (see Stage 2 gate document):
 *  - Account existence (not discoverability) gates suppress-vs-keep; discoverability
 *    gates the representation (account vs suppressed/preserved). This prevents
 *    sending a recipient who already has an account through account creation.
 *  - Email and UID lookup paths are resolved independently (AccountDiscoveryPolicy).
 *  - Historical signers (requester-scoped, safe) are never lost: even when unify()
 *    retained the external twin, the provenance snapshot restores the historical.
 *  - Failure is isolated per candidate; a single failing item never restores
 *    suppressed externals for the rest. '_via' provenance is strictly internal.
 */
class SignerIdentityReconciler {
	public function __construct(
		private AccountDiscoveryPolicy $discoveryPolicy,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Build request-scoped provenance memory from the raw collaborator-search
	 * result ({'exact' => {type => items}, type => items}), BEFORE the unify
	 * passes collapse colliding items. Keyed by value.shareWith.
	 *
	 * @param array<string, mixed> $rawResult
	 * @return array<string, array{hadHistorical: bool, hadExternal: bool, historicalItem: ?array}>
	 */
	public function buildSnapshot(array $rawResult): array {
		$snapshot = [];
		$record = function (array $item) use (&$snapshot): void {
			$shareWith = (string)($item['value']['shareWith'] ?? '');
			if ($shareWith === '') {
				return;
			}
			$shareType = $item['value']['shareType'] ?? null;
			if (!isset($snapshot[$shareWith])) {
				$snapshot[$shareWith] = ['hadHistorical' => false, 'hadExternal' => false, 'historicalItem' => null];
			}
			if ($shareType === SignerPlugin::TYPE_SIGNER) {
				$snapshot[$shareWith]['hadHistorical'] = true;
				if ($snapshot[$shareWith]['historicalItem'] === null) {
					$snapshot[$shareWith]['historicalItem'] = $item;
				}
			} elseif ($shareType === IShare::TYPE_EMAIL) {
				$snapshot[$shareWith]['hadExternal'] = true;
			}
		};

		foreach (($rawResult['exact'] ?? []) as $items) {
			if (!is_array($items)) {
				continue;
			}
			foreach ($items as $item) {
				if (is_array($item)) {
					$record($item);
				}
			}
		}
		foreach ($rawResult as $type => $items) {
			if ($type === 'exact' || !is_array($items)) {
				continue;
			}
			foreach ($items as $item) {
				if (is_array($item)) {
					$record($item);
				}
			}
		}
		return $snapshot;
	}

	/**
	 * Reconcile the post-filterAccountMatches flat list. Returns the reconciled,
	 * account-deduplicated list (raw items; value.shareType still present). Each
	 * item is processed in isolation; a failure falls back to that item's safe rule
	 * and never affects the rest.
	 *
	 * @param list<array> $items
	 * @param array<string, array{hadHistorical: bool, hadExternal: bool, historicalItem: ?array}> $snapshot
	 * @return list<array>
	 */
	public function reconcile(array $items, array $snapshot): array {
		$out = [];
		foreach ($items as $item) {
			try {
				foreach ($this->reconcileItem($item, $snapshot) as $row) {
					$out[] = $row;
				}
			} catch (\Throwable $e) {
				$this->logException('reconcile: candidate exception', $e, $item);
				foreach ($this->safeFallbackForItem($item, $snapshot) as $row) {
					$out[] = $row;
				}
			}
		}
		return $this->dedupeAccounts($out);
	}

	/**
	 * Snapshot-aware safe filter for a catastrophic reconciler failure. Applied by
	 * the controller to the raw (pre-format) list instead of restoring it verbatim:
	 * confirmed accounts (already discoverability-gated by EmailAccountPlugin),
	 * requester-scoped historical signers, and phone results survive; an external
	 * suggestion survives only when the snapshot shows it masked a historical,
	 * otherwise it is dropped (fail closed — account status is unverifiable here).
	 *
	 * @param list<array> $items
	 * @param array<string, array{hadHistorical: bool, hadExternal: bool, historicalItem: ?array}> $snapshot
	 * @return list<array>
	 */
	public function safeFilter(array $items, array $snapshot): array {
		$out = [];
		$dropped = 0;
		foreach ($items as $item) {
			$shareType = $item['value']['shareType'] ?? null;
			if ($shareType === IShare::TYPE_EMAIL) {
				$email = (string)($item['value']['shareWith'] ?? '');
				if ($email !== '' && ($snapshot[$email]['hadHistorical'] ?? false)) {
					$out[] = $item;
				} else {
					$dropped++;
				}
				continue;
			}
			// 54 accounts, 50 historical, 51/52/53 phone, and any other
			// non-external type are preserved unchanged.
			$out[] = $item;
		}
		if ($dropped > 0) {
			$this->logger->error('[Libresign] reconcile: fatal, applied safe filter', [
				'droppedExternalSuggestions' => $dropped,
			]);
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $item
	 * @param array<string, array{hadHistorical: bool, hadExternal: bool, historicalItem: ?array}> $snapshot
	 * @return list<array>
	 */
	private function reconcileItem(array $item, array $snapshot): array {
		$shareType = $item['value']['shareType'] ?? null;
		$method = (string)($item['method'] ?? '');

		// Already-account rows and phone rows are left untouched. Accounts remain a
		// dedup target; phone rows need nothing from the reconciler.
		if ($this->isPassThrough($shareType)) {
			return [$item];
		}
		if (!$this->isEmailIdentity($shareType, $method)) {
			return [$item];
		}

		$email = (string)($item['value']['shareWith'] ?? '');
		if ($email === '') {
			return [$item];
		}

		$resolution = $this->discoveryPolicy->resolve($email);
		$emailPath = $resolution['email'];
		$uidPath = $resolution['uid'];
		$anyDiscoverable = $emailPath['discoverable'] || $uidPath['discoverable'];
		$exists = $emailPath['account'] !== null || $uidPath['account'] !== null;

		if ($anyDiscoverable) {
			return $this->upgradeRows($emailPath, $uidPath, $email);
		}

		if ($exists) {
			if ($this->isHistorical($shareType, $email, $snapshot)) {
				return [$this->preserveHistorical($item, $email, $snapshot)];
			}
			// Existing, non-discoverable, purely external -> suppress (Option A):
			// do not downgrade an account to an external suggestion; avoids routing
			// an existing-account recipient through account creation.
			return [];
		}

		// No account: keep (requester's historical signer or a genuine external).
		return [$item];
	}

	/**
	 * @param array{account: ?\OCP\IUser, discoverable: bool} $emailPath
	 * @param array{account: ?\OCP\IUser, discoverable: bool} $uidPath
	 * @return list<array>
	 */
	private function upgradeRows(array $emailPath, array $uidPath, string $email): array {
		$emailDiscoverable = $emailPath['discoverable'];
		$uidDiscoverable = $uidPath['discoverable'];

		if ($emailDiscoverable && $uidDiscoverable) {
			$emailAccount = $emailPath['account'];
			$uidAccount = $uidPath['account'];
			if ($emailAccount !== null && $uidAccount !== null
				&& $emailAccount->getUID() === $uidAccount->getUID()) {
				return [$this->makeAccountRow($emailAccount, $email, ['email', 'uid'])];
			}
			$rows = [];
			if ($emailAccount !== null) {
				$rows[] = $this->makeAccountRow($emailAccount, $email, ['email']);
			}
			if ($uidAccount !== null) {
				$rows[] = $this->makeAccountRow($uidAccount, $email, ['uid']);
			}
			return $rows;
		}
		if ($emailDiscoverable && $emailPath['account'] !== null) {
			return [$this->makeAccountRow($emailPath['account'], $email, ['email'])];
		}
		if ($uidDiscoverable && $uidPath['account'] !== null) {
			return [$this->makeAccountRow($uidPath['account'], $email, ['uid'])];
		}
		return [];
	}

	/**
	 * @param list<string> $via
	 * @return array<string, mixed>
	 */
	private function makeAccountRow(\OCP\IUser $account, string $email, array $via): array {
		$uid = $account->getUID();
		$displayName = $account->getDisplayName() !== '' ? $account->getDisplayName() : $uid;
		return [
			'label' => $displayName,
			'shareWithDisplayNameUnique' => $email,
			'method' => 'account',
			'value' => [
				'shareType' => EmailAccountPlugin::TYPE_SIGNER_EMAIL_ACCOUNT,
				'shareWith' => $uid,
			],
			'_via' => $via,
		];
	}

	/**
	 * @param array<string, mixed> $item
	 * @param array<string, array{hadHistorical: bool, hadExternal: bool, historicalItem: ?array}> $snapshot
	 * @return array<string, mixed>
	 */
	private function preserveHistorical(array $item, string $email, array $snapshot): array {
		// If the surviving item is already the historical row, keep it. Otherwise
		// unify() kept the external twin; restore the historical representation from
		// the snapshot so the requester's prior signer is not lost or relabelled.
		if (($item['value']['shareType'] ?? null) === SignerPlugin::TYPE_SIGNER) {
			return $item;
		}
		$historical = $snapshot[$email]['historicalItem'] ?? null;
		if (is_array($historical)) {
			return $historical;
		}
		return $item;
	}

	/**
	 * Candidate-level safe fallback (lookup/policy threw): preserve historicals,
	 * suppress unverifiable externals (keeping any that masked a historical), keep
	 * everything else unchanged. No account upgrade is attempted.
	 *
	 * @param array<string, mixed> $item
	 * @param array<string, array{hadHistorical: bool, hadExternal: bool, historicalItem: ?array}> $snapshot
	 * @return list<array>
	 */
	private function safeFallbackForItem(array $item, array $snapshot): array {
		$shareType = $item['value']['shareType'] ?? null;
		if ($shareType === SignerPlugin::TYPE_SIGNER) {
			return [$item];
		}
		if ($shareType === IShare::TYPE_EMAIL) {
			$email = (string)($item['value']['shareWith'] ?? '');
			if ($email !== '' && ($snapshot[$email]['hadHistorical'] ?? false)) {
				return [$item];
			}
			return [];
		}
		return [$item];
	}

	/**
	 * @param list<array> $list
	 * @return list<array>
	 */
	private function dedupeAccounts(array $list): array {
		$seen = [];
		$out = [];
		foreach ($list as $item) {
			if (($item['method'] ?? null) !== 'account') {
				$out[] = $item;
				continue;
			}
			$uid = (string)($item['value']['shareWith'] ?? '');
			if ($uid !== '' && isset($seen[$uid])) {
				$idx = $seen[$uid];
				$existingVia = $out[$idx]['_via'] ?? [];
				$incomingVia = $item['_via'] ?? [];
				$out[$idx]['_via'] = array_values(array_unique(array_merge($existingVia, $incomingVia)));
				continue;
			}
			if ($uid !== '') {
				$seen[$uid] = count($out);
			}
			$out[] = $item;
		}
		return $out;
	}

	private function isPassThrough(mixed $shareType): bool {
		return in_array($shareType, [
			EmailAccountPlugin::TYPE_SIGNER_EMAIL_ACCOUNT,
			AccountPhonePlugin::TYPE_SIGNER_ACCOUNT_PHONE,
			ContactPhonePlugin::TYPE_SIGNER_CONTACT_PHONE,
			ManualPhonePlugin::TYPE_SIGNER_MANUAL_PHONE,
		], true);
	}

	private function isEmailIdentity(mixed $shareType, string $method): bool {
		if ($shareType === IShare::TYPE_EMAIL) {
			return true;
		}
		return $shareType === SignerPlugin::TYPE_SIGNER && $method === 'email';
	}

	/**
	 * @param array<string, array{hadHistorical: bool, hadExternal: bool, historicalItem: ?array}> $snapshot
	 */
	private function isHistorical(mixed $shareType, string $email, array $snapshot): bool {
		if ($shareType === SignerPlugin::TYPE_SIGNER) {
			return true;
		}
		return (bool)($snapshot[$email]['hadHistorical'] ?? false);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function logException(string $context, \Throwable $e, array $item): void {
		$shareWith = (string)($item['value']['shareWith'] ?? '');
		$this->logger->warning('[Libresign] ' . $context, [
			'exception' => $e::class,
			'message' => $e->getMessage(),
			'ref' => $shareWith === '' ? 'n/a' : substr(hash('sha256', $shareWith), 0, 8),
			'shareType' => $item['value']['shareType'] ?? null,
		]);
	}
}
