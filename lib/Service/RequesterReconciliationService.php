<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\IdentifyMethodMapper;
use OCA\Libresign\Db\PublicWorkflow;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Exception\LibresignException;
use OCP\Accounts\IAccountManager;
use OCP\Config\IUserConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Reconciles provisional email signer identities with trusted accounts.
 *
 * Converts identity methods in place, preserving the sign request and its
 * visible elements. Reconciliation is trust-gated, transactional, and
 * idempotent; unresolved identities remain unchanged.
 *
 * Invariants:
 * - Converts only when the account clears the trust gate (a trusted OIDC provider,
 *   or the createToSign invitation marker) AND the email matches; otherwise it
 *   changes nothing and returns an explicit unresolved status.
 * - The claim is a separate, already-committed stage; an unresolved reconciliation
 *   never undoes it.
 * - Never publishes, notifies, or reserves/consumes credits (those belong to the
 *   DRAFT -> ABLE_TO_SIGN transition). Conversion is transactional and idempotent.
 */
class RequesterReconciliationService {
	public const NOT_REQUESTED = 'not_requested';
	public const RECONCILED = 'reconciled';
	public const ALREADY_RECONCILED = 'already_reconciled';
	public const UNRESOLVED_NO_EMAIL = 'unresolved_no_email';
	public const UNRESOLVED_NO_REQUEST = 'unresolved_no_request';
	public const UNRESOLVED_UNVERIFIED = 'unresolved_unverified';
	public const UNRESOLVED_CONFLICT = 'unresolved_conflict';
	public const UNRESOLVED_ERROR = 'unresolved_error';

	public function __construct(
		private IDBConnection $connection,
		private FileMapper $fileMapper,
		private SignRequestMapper $signRequestMapper,
		private IdentifyMethodMapper $identifyMethodMapper,
		private IAccountManager $accountManager,
		private TrustedOidcProviderPolicy $trustPolicy,
		private IUserManager $userManager,
		private IUserConfig $userConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reconcile the requester's email signer with the authenticated claimant.
	 *
	 * Returns a status constant for each outcome. Reconciliation failures do not
	 * invalidate the completed claim.
	 */
	public function reconcile(PublicWorkflow $workflow, IUser $claimant): string {
		try {
			if (!$workflow->getRequesterIsSigner()) {
				return self::NOT_REQUESTED;
			}
			$requesterEmail = $workflow->getRequesterEmail();
			if ($requesterEmail === null || trim($requesterEmail) === '') {
				return self::UNRESOLVED_NO_EMAIL;
			}
			$requesterEmail = trim($requesterEmail);

			$file = $this->fileMapper->getByUuid((string)$workflow->getFileUuid());

			// Only reconcile a document owned by the claimant.
			if ($file->getUserId() !== $claimant->getUID()) {
				return self::UNRESOLVED_ERROR;
			}
			$fileId = (int)$file->getId();
			$uid = $claimant->getUID();

			$accountSr = $this->signRequestMapper->getByIdentifierAndFileId('account', $uid, $fileId);
			$emailSr = $this->signRequestMapper->getByIdentifierAndFileId('email', $requesterEmail, $fileId);

			// Idempotency: already converted (account request present, provisional gone).
			if ($accountSr !== null && $emailSr === null) {
				return self::ALREADY_RECONCILED;
			}

			// Do not merge distinct requests; the existing account request may have progressed.
			if ($accountSr !== null && $emailSr !== null && $accountSr->getId() !== $emailSr->getId()) {
				return self::UNRESOLVED_CONFLICT;
			}
			// The same request already carries the account identity.
			if ($accountSr !== null && $emailSr !== null && $accountSr->getId() === $emailSr->getId()) {
				return self::ALREADY_RECONCILED;
			}
			if ($emailSr === null) {
				// Self-signing was requested, but no provisional email request exists.
				return self::UNRESOLVED_NO_REQUEST;
			}

			// Require a trusted account and an exact email match before conversion.
			if (!$this->isTrustedAccountForEmail($claimant, $requesterEmail)) {
				return self::UNRESOLVED_UNVERIFIED;
			}

			return $this->convertInPlace($emailSr->getId(), $requesterEmail, $uid);
		} catch (\Throwable $e) {
			$this->logger->error('[PublicWorkflow] requester reconciliation failed', ['exception' => $e]);
			return self::UNRESOLVED_ERROR;
		}
	}

	/**
	 * Convert the request's email identity to an account identity in one transaction.
	 *
	 * Preserves the sign request and its visible elements. Rolls back if no matching
	 * email identity can be converted.
	 */
	private function convertInPlace(int $signRequestId, string $requesterEmail, string $uid): string {
		$this->connection->beginTransaction();
		try {
			$methods = $this->identifyMethodMapper->getIdentifyMethodsFromSignRequestId($signRequestId);
			$converted = false;
			foreach ($methods as $method) {
				if ($method->getIdentifierKey() === 'email'
					&& strcasecmp($method->getIdentifierValue(), $requesterEmail) === 0) {
					$method->setIdentifierKey('account');
					$method->setIdentifierValue($uid);
					$this->identifyMethodMapper->update($method);
					$converted = true;
				}
			}
			if (!$converted) {
				$this->connection->rollBack();
				return self::UNRESOLVED_NO_REQUEST;
			}
			$this->connection->commit();
			return self::RECONCILED;
		} catch (\Throwable $e) {
			$this->connection->rollBack();
			throw $e;
		}
	}

	/** Evaluate account trust; unexpected errors fail closed. */
	private function isTrustedAccountForEmail(IUser $user, string $email): bool {
		try {
			return $this->accountTrustedForEmail($user, $email);
		} catch (\Throwable $e) {
			$this->logger->warning('[PublicWorkflow] could not evaluate claimant trust', ['exception' => $e]);
			return false;
		}
	}

	/**
	 * Return the account email, falling back to an email-shaped UID when the
	 * account's email property is empty.
	 *
	 * Never overrides an existing email or infers an address from another field.
	 */
	private function attributableEmail(IUser $user): string {
		$accountEmail = trim((string)($user->getEMailAddress() ?? ''));
		if ($accountEmail !== '') {
			return $accountEmail;
		}
		$uid = trim($user->getUID());
		if ($uid !== '' && filter_var($uid, FILTER_VALIDATE_EMAIL) !== false) {
			return $uid;
		}
		return '';
	}

	/**
	 * Check that the account's attributable email matches and its identity is trusted.
	 *
	 * Supports trusted OIDC accounts and accounts with a verified invitation marker.
	 */
	private function accountTrustedForEmail(IUser $account, string $email): bool {
		$accountEmail = $this->attributableEmail($account);
		if ($accountEmail === '' || strcasecmp($accountEmail, trim($email)) !== 0) {
			return false;
		}
		return $this->trustPolicy->isTrustedOidcAccount($account)
			|| $this->hasInvitationTrustedEmail($account->getUID(), $email);
	}

	/**
	 * Check whether the account has an invitation trust marker for this email.
	 */
	private function hasInvitationTrustedEmail(string $uid, string $email): bool {
		$trusted = trim($this->userConfig->getValueString($uid, Application::APP_ID, AccountService::TRUSTED_EMAIL_KEY, ''));
		return $trusted !== '' && strcasecmp($trusted, trim($email)) === 0;
	}

	/**
	 * Resolve an email to a matching trusted account UID, or null.
	 *
	 * Checks the account email property first, then the legacy email-as-UID format.
	 * Only exact matches that pass the trust checks are accepted.
	 *
	 * @return string|null
	 */
	private function resolveTrustedAccountUid(string $email): ?string {
		$email = trim($email);
		if ($email === '') {
			return null;
		}
		// First, check accounts whose email property matches.
		$accounts = $this->userManager->getByEmail($email);
		if (count($accounts) === 1) {
			$account = reset($accounts);
			if ($this->accountTrustedForEmail($account, $email)) {
				return $account->getUID();
			}
		}
		// Legacy accounts may use the email as their UID while leaving the email property empty.
		if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
			$account = $this->userManager->get($email);
			if ($account !== null
				&& strcasecmp($account->getUID(), $email) === 0
				&& $this->accountTrustedForEmail($account, $email)) {
				return $account->getUID();
			}
		}
		return null;
	}

	/**
	 * Reconcile eligible email signers on a file with their trusted accounts.
	 *
	 * Converts matching identities in place and leaves unresolved signers unchanged.
	 * Never throws.
	 *
	 * @return array{converted: int, scanned: int}
	 */
	public function reconcileFileSigners(int $fileId): array {
		$converted = 0;
		$scanned = 0;
		try {
			foreach ($this->signRequestMapper->getByFileId($fileId) as $signRequest) {
				if ($signRequest->getSigned() !== null) {
					continue;
				}
				$signRequestId = (int)$signRequest->getId();
				$methods = $this->identifyMethodMapper->getIdentifyMethodsFromSignRequestId($signRequestId);
				foreach ($methods as $method) {
					if ($method->getIdentifierKey() !== 'email') {
						continue;
					}
					$scanned++;
					$email = (string)$method->getIdentifierValue();
					$uid = $this->resolveTrustedAccountUid($email);
					if ($uid === null) {
						continue;
					}
					if ($this->convertInPlace($signRequestId, $email, $uid) === self::RECONCILED) {
						$converted++;
					}
				}
			}
		} catch (\Throwable $e) {
			$this->logger->error('[PublicWorkflow] signer reconciliation (claim) failed', ['exception' => $e]);
		}
		return ['converted' => $converted, 'scanned' => $scanned];
	}

	/**
	 * Reconcile the authenticated user's own unsigned public-workflow signer requests.
	 *
	 * Uses the user's attributable email and the same trust checks as claim-time
	 * reconciliation. Never throws.
	 *
	 * @return array{converted: int}
	 */
	public function reconcileAuthenticatedSigner(IUser $user): array {
		$converted = 0;
		try {
			$email = $this->attributableEmail($user);
			if ($email === '') {
				return ['converted' => 0];
			}
			$uid = $user->getUID();
			if (!$this->trustPolicy->isTrustedOidcAccount($user)
				&& !$this->hasInvitationTrustedEmail($uid, $email)) {
				return ['converted' => 0];
			}
			foreach ($this->signRequestMapper->findUnsignedPublicWorkflowSignRequestIdsByEmail($email) as $signRequestId) {
				if ($this->convertInPlace((int)$signRequestId, $email, $uid) === self::RECONCILED) {
					$converted++;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->error('[PublicWorkflow] signer self-reconciliation (login) failed', ['exception' => $e]);
		}
		return ['converted' => $converted];
	}
}
