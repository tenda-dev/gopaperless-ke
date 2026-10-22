<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\File;
use OCA\Libresign\Db\FileElementMapper;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\PublicWorkflow;
use OCA\Libresign\Db\PublicWorkflowMapper;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Exception\LibresignException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Owns the public-workflow capability lifecycle: creation (with a server-created
 * temporary owner), capability hashing, resolution from a raw token, expiry and
 * revocation, and single-document association.
 *
 * Security invariants:
 *  - The raw capability is never stored or logged; only its SHA-256 hash is
 *    persisted (unique index).
 *  - The owner is resolved from the persisted row, never from client input.
 *  - A browser-supplied UID can never select or override the owner.
 *  - Missing / malformed / revoked / expired capabilities resolve to a generic
 *    rejection that does not reveal whether a workflow exists.
 */
class PublicWorkflowService {
	public const COOKIE_NAME = 'gopaperless_pw';
	private const TOKEN_BYTES = 32;         // 256-bit capability
	private const EXPIRY_HOURS = 1;      // 1 hour
	private const HASH_HEX_LENGTH = 64;     // sha256 hex

	public function __construct(
		private IDBConnection $connection,
		private PublicWorkflowMapper $mapper,
		private AccountService $accountService,
		private FileMapper $fileMapper,
		private FileElementMapper $fileElementMapper,
		private SignRequestMapper $signRequestMapper,
		private FileElementService $fileElementService,
		private FolderService $folderService,
		private ILockingProvider $lockingProvider,
		private IUserManager $userManager,
		private ITimeFactory $timeFactory,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Create a workflow and its one temporary owner.
	 *
	 * The temporary user is created before (and outside) the DB transaction; if the
	 * row cannot be persisted it is compensated (deleted) so no orphan owner remains.
	 *
	 * @return array{workflow: PublicWorkflow, rawToken: string} rawToken is for the
	 *                                                           HttpOnly cookie only; never persisted or logged.
	 */
	public function create(): array {
		$rawToken = bin2hex(random_bytes(self::TOKEN_BYTES));
		$tokenHash = $this->hashToken($rawToken);

		$owner = $this->accountService->createTemporaryUser();

		try {
			$now = $this->timeFactory->getDateTime();
			$expiresAt = (clone $now)->modify('+' . $this->expiryHours() . ' hours');

			$workflow = new PublicWorkflow();
			$workflow->setTokenHash($tokenHash);
			$workflow->setOwnerUid($owner->getUID());
			$workflow->setStatus(PublicWorkflow::STATUS_ACTIVE);
			$workflow->setCreatedAt($now);
			$workflow->setExpiresAt($expiresAt);

			$this->connection->beginTransaction();
			try {
				$workflow = $this->mapper->insert($workflow);
				$this->connection->commit();
			} catch (\Throwable $e) {
				$this->connection->rollBack();
				throw $e;
			}

			return ['workflow' => $workflow, 'rawToken' => $rawToken];
		} catch (\Throwable $e) {
			$this->compensateOwner($owner);
			$this->logger->error('[PublicWorkflow] Workflow creation failed; temporary owner compensated', ['exception' => $e]);
			throw new LibresignException('Could not start the workflow');
		}
	}

	/**
	 * Resolve and validate a workflow from the raw capability token (the cookie
	 * value). Throws a generic LibresignException for every failure mode so the
	 * caller cannot distinguish "no such token" from "expired" from "revoked".
	 */
	public function resolveFromToken(?string $rawToken): PublicWorkflow {
		if (!is_string($rawToken) || !$this->isWellFormedToken($rawToken)) {
			throw new LibresignException('Invalid workflow');
		}

		$tokenHash = $this->hashToken($rawToken);
		$workflow = $this->mapper->findByTokenHash($tokenHash);

		// Constant-time compare in addition to the unique-index lookup.
		if ($workflow === null || !hash_equals($workflow->getTokenHash(), $tokenHash)) {
			throw new LibresignException('Invalid workflow');
		}
		if ($workflow->getStatus() !== PublicWorkflow::STATUS_ACTIVE) {
			throw new LibresignException('Invalid workflow');
		}
		if ($this->isExpired($workflow)) {
			throw new LibresignException('Invalid workflow');
		}
		// The owner must still exist and still be a temporary GoPaperless user.
		$owner = $this->userManager->get($workflow->getOwnerUid());
		if (!$owner instanceof IUser || !$this->accountService->isTemporaryUser($owner)) {
			throw new LibresignException('Invalid workflow');
		}

		return $workflow;
	}

	/**
	 * Load the workflow's temporary owner. The UID comes only from the persisted
	 * row — never from the request.
	 */
	public function resolveOwner(PublicWorkflow $workflow): IUser {
		$owner = $this->userManager->get($workflow->getOwnerUid());
		if (!$owner instanceof IUser) {
			throw new LibresignException('Invalid workflow');
		}
		return $owner;
	}

	public function hasDocument(PublicWorkflow $workflow): bool {
		return $workflow->getFileUuid() !== null && $workflow->getFileUuid() !== '';
	}

	/**
	 * Create the workflow's one document under a per-workflow row lock so two
	 * concurrent first uploads cannot both create a file. In one transaction: lock
	 * the row (forUpdate), re-check the one-document invariant after the lock,
	 * resolve the owner, run $createFile, record its UUID, commit. A competing
	 * request blocks until commit, then sees file_uuid set and is rejected. The
	 * lock is held for the whole critical section.
	 *
	 * A rolled-back transaction can leave an orphan physical node (filesystem
	 * writes are not transactional); it is owned by the temp user and carries no
	 * authorization, so it is not speculatively deleted.
	 *
	 * @param callable(IUser): File $createFile Runs inside the lock; returns the file.
	 */
	public function createDocument(PublicWorkflow $workflow, callable $createFile): File {
		$this->connection->beginTransaction();
		try {
			$locked = $this->mapper->findByTokenHashForUpdate($workflow->getTokenHash());
			if ($locked === null) {
				throw new LibresignException('Invalid workflow');
			}
			// Authoritative one-document check, AFTER acquiring the lock.
			if ($this->hasDocument($locked)) {
				throw new LibresignException('This workflow already has a document');
			}
			$owner = $this->resolveOwner($locked);

			$file = $createFile($owner);

			$locked->setFileUuid((string)$file->getUuid());
			$this->mapper->update($locked);
			$this->connection->commit();

			return $file;
		} catch (\Throwable $e) {
			$this->connection->rollBack();
			throw $e;
		}
	}

	/**
	 * Record the single document's LibreSign UUID on the workflow (reference
	 * only; ownership stays on the file record). Enforces one document per
	 * workflow on the backend.
	 */
	public function attachDocument(PublicWorkflow $workflow, string $fileUuid): void {
		if ($this->hasDocument($workflow)) {
			throw new LibresignException('This workflow already has a document');
		}
		$workflow->setFileUuid($fileUuid);
		$this->mapper->update($workflow);
	}

	public function revoke(PublicWorkflow $workflow): void {
		$workflow->setStatus(PublicWorkflow::STATUS_REVOKED);
		$this->mapper->update($workflow);
	}

	/**
	 * Delete one visible signature element from this workflow's DRAFT document.
	 *
	 * Authorisation is entirely server-side: the document is resolved from the
	 * workflow's own stored file_uuid (never a request id), confirmed to belong to
	 * the resolved owner, and the element confirmed to belong to that document. A
	 * missing or foreign element returns a generic 'not found', revealing nothing.
	 */
	public function deleteVisibleElement(PublicWorkflow $workflow, int $elementId): void {
		if (!$this->hasDocument($workflow)) {
			throw new LibresignException('Upload a document first');
		}
		$owner = $this->resolveOwner($workflow);
		try {
			$file = $this->fileMapper->getByUuid((string)$workflow->getFileUuid());
		} catch (DoesNotExistException) {
			throw new LibresignException('Invalid workflow');
		}
		if ($file->getUserId() !== $owner->getUID()) {
			// The stored document is not owned by the resolved temporary owner.
			throw new LibresignException('Invalid workflow');
		}
		try {
			$element = $this->fileElementMapper->getById($elementId);
		} catch (DoesNotExistException) {
			throw new LibresignException('Signature position not found');
		}
		if ($element->getFileId() !== $file->getId()) {
			// Element belongs to a different document — do not reveal that.
			throw new LibresignException('Signature position not found');
		}
		$this->fileElementService->deleteVisibleElement($elementId);
	}

	/**
	 * Resolve the physical PDF node of this workflow's DRAFT document for the
	 * capability-gated public PDF endpoint. Server-side authorisation mirrors
	 * deleteVisibleElement(): the document comes from the workflow's own stored
	 * file_uuid (never a request uuid) and must still belong to the resolved
	 * temporary owner. The node is read from the owner's storage, so it never
	 * depends on or weakens the validation-document access controls — a visitor
	 * can only reach the one document their capability cookie maps to.
	 */
	public function getDocumentFile(PublicWorkflow $workflow): \OCP\Files\File {
		if (!$this->hasDocument($workflow)) {
			throw new LibresignException('Upload a document first');
		}
		$owner = $this->resolveOwner($workflow);
		$fileUuid = (string)$workflow->getFileUuid();
		try {
			$file = $this->fileMapper->getByUuid($fileUuid);
		} catch (DoesNotExistException) {
			throw new LibresignException('Invalid workflow');
		}
		if ($file->getUserId() !== $owner->getUID()) {
			// The stored document is not owned by the resolved temporary owner.
			throw new LibresignException('Invalid workflow');
		}
		// Resolves the node via the owner's storage; throws DoesNotExistException
		// when the node is gone, which the caller maps to a generic 404.
		return $this->accountService->getPdfByUuid($fileUuid);
	}

	private const CLAIM_LOCK_PREFIX = 'libresign/public-workflow-claim/';
	// How long a CLAIMING row must sit before a leaked/aborted claim lock is
	// treated as recoverable by the SAME claimant (distinguishes a dead lock from
	// a genuinely live concurrent transfer, which completes in well under this).
	private const CLAIM_STALE_SECONDS = 15;

	/**
	 * Persist the requester's email + self-signer intent on an ACTIVE workflow.
	 * DRAFT-only: records intent, reserves/sends/charges nothing. Email is stored
	 * for display/matching/UX; it is never an authorization factor for claiming.
	 */
	public function saveRequesterIdentity(PublicWorkflow $workflow, ?string $email, bool $isSigner): void {
		$trimmed = $email !== null && trim($email) !== '' ? trim($email) : null;
		if ($trimmed !== null && filter_var($trimmed, FILTER_VALIDATE_EMAIL) === false) {
			throw new LibresignException('Enter a valid email address.');
		}
		$workflow->setRequesterEmail($trimmed);
		$workflow->setRequesterIsSigner($isSigner);
		$workflow->setUpdatedAt($this->timeFactory->getDateTime());
		$this->mapper->update($workflow);
	}

	/**
	 * Resolve a workflow for the CLAIM path. Unlike resolveFromToken (ACTIVE only),
	 * this accepts the claim lifecycle states (active/claiming/claimed) so a claim
	 * can start, resume, or replay idempotently; only revoked and malformed/missing
	 * are rejected. ACTIVE-row expiry is enforced later, under lock, in beginClaim.
	 * A CLAIMED row is accepted even though its temp owner is gone.
	 */
	public function resolveForClaim(?string $rawToken): PublicWorkflow {
		if (!is_string($rawToken) || !$this->isWellFormedToken($rawToken)) {
			throw new LibresignException('Invalid workflow');
		}
		$tokenHash = $this->hashToken($rawToken);
		$workflow = $this->mapper->findByTokenHash($tokenHash);
		if ($workflow === null || !hash_equals($workflow->getTokenHash(), $tokenHash)) {
			throw new LibresignException('Invalid workflow');
		}
		if ($workflow->getStatus() === PublicWorkflow::STATUS_REVOKED) {
			throw new LibresignException('Invalid workflow');
		}
		$owner = $this->userManager->get($workflow->getOwnerUid());
		if (!$owner instanceof IUser || !$this->accountService->isTemporaryUser($owner)) {
			// A fully claimed workflow has had its temp owner deleted — still valid
			// for idempotent replay; anything else with a missing/!temp owner is not.
			if ($workflow->getStatus() !== PublicWorkflow::STATUS_CLAIMED) {
				throw new LibresignException('Invalid workflow');
			}
		}
		return $workflow;
	}

	/**
	 * Describe a workflow's claim eligibility for an authenticated claimant
	 * WITHOUT mutating anything (for the confirm/mismatch UI). The email match is
	 * reported for UX only and is NEVER an authorization factor.
	 *
	 * @return array{requesterEmail: ?string, emailMatches: bool, alreadyClaimedByMe: bool, claimable: bool}
	 */
	public function describeClaim(PublicWorkflow $workflow, IUser $claimant, ?string $accountEmail): array {
		$requesterEmail = $workflow->getRequesterEmail();
		$emailMatches = $requesterEmail !== null && $accountEmail !== null
			&& strcasecmp(trim($requesterEmail), trim($accountEmail)) === 0;
		$status = $workflow->getStatus();
		$alreadyClaimedByMe = $status === PublicWorkflow::STATUS_CLAIMED
			&& $workflow->getClaimedByUid() === $claimant->getUID();
		$claimable = $this->hasDocument($workflow) && (
			($status === PublicWorkflow::STATUS_ACTIVE && !$this->isExpired($workflow))
			|| $status === PublicWorkflow::STATUS_CLAIMING
			|| $alreadyClaimedByMe
		);
		return [
			'requesterEmail' => $requesterEmail,
			'emailMatches' => $emailMatches,
			'alreadyClaimedByMe' => $alreadyClaimedByMe,
			'claimable' => $claimable,
		];
	}

	/**
	 * Claim this workflow for an authenticated user. Authorization (valid capability
	 * cookie + authenticated session) is established by the caller; a matching email
	 * is NOT authorization and is not checked here.
	 *
	 * Orchestration: beginClaim (row-locked flip to CLAIMING) -> transferDocumentOwnership
	 * (idempotent physical copy + File re-point) -> completeClaim (CLAIMED + temp-user
	 * deletion). A failed transfer leaves the row CLAIMING (recoverable), never CLAIMED.
	 */
	public function claim(PublicWorkflow $workflow, IUser $claimant): File {
		$uid = $claimant->getUID();
		$fileUuid = (string)$workflow->getFileUuid();

		// Cluster-wide exclusive lock for the whole claim; acquireLock throws when
		// held, serialising the file copy. Not a DB lock, so no transaction spans
		// the file I/O.
		$lockKey = $this->claimLockKey($workflow);
		$haveLock = false;
		try {
			$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			$haveLock = true;
			$this->logger->info('[PublicWorkflow] claim lock acquired', ['uid' => $uid, 'file' => $fileUuid]);
		} catch (LockedException) {
			// Lock held. Recover ONLY our own stale interrupted claim (same claimant,
			// CLAIMING, claim_started_at older than the stale window): an aborted
			// request leaks the lock for its whole TTL and would otherwise 409 every
			// retry. Safe because transferDocumentOwnership is idempotent and
			// re-validates under the DB row lock. A different user, or a recent
			// (possibly live) claim, still conflicts.
			$fresh = $this->mapper->findByTokenHash($workflow->getTokenHash());
			$startedAt = $fresh?->getClaimStartedAt();
			$stale = $startedAt !== null
				&& $startedAt->getTimestamp() < ($this->timeFactory->getTime() - self::CLAIM_STALE_SECONDS);
			if ($fresh === null
				|| $fresh->getStatus() !== PublicWorkflow::STATUS_CLAIMING
				|| $fresh->getClaimedByUid() !== $uid
				|| !$stale) {
				throw new LibresignException('This document is already being claimed');
			}
			$this->logger->warning('[PublicWorkflow] claim lock held; recovering same-claimant stale interrupted claim without the lock', ['uid' => $uid, 'file' => $fileUuid]);
		}
		try {
			$locked = $this->beginClaim($workflow, $claimant);
			$this->logger->info('[PublicWorkflow] claim begun (claiming)', ['uid' => $uid, 'file' => $fileUuid]);
			try {
				$this->logger->info('[PublicWorkflow] ownership transfer start', ['uid' => $uid, 'file' => $fileUuid]);
				$file = $this->transferDocumentOwnership($locked, $claimant);
				$this->logger->info('[PublicWorkflow] ownership transfer done', ['uid' => $uid, 'file' => $fileUuid, 'nodeId' => $file->getNodeId()]);
			} catch (LibresignException $e) {
				throw $e;
			} catch (\Throwable $e) {
				// Never mark CLAIMED on a failed transfer; the row stays CLAIMING and
				// the deterministic destination lets a same-claimant retry recover.
				$this->logger->error('[PublicWorkflow] claim transfer failed; left recoverable in claiming', ['uid' => $uid, 'file' => $fileUuid, 'exception' => $e]);
				throw new LibresignException('Could not transfer the document');
			}
			$this->completeClaim($locked, $claimant);
			$this->logger->info('[PublicWorkflow] claim completed', ['uid' => $uid, 'file' => $fileUuid]);
			return $file;
		} finally {
			if ($haveLock) {
				try {
					$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
				} catch (\Throwable $e) {
					$this->logger->warning('[PublicWorkflow] could not release claim lock', ['exception' => $e]);
				}
			}
		}
	}

	/**
	 * Build the distributed claim lock key, hashed to a fixed length. prefix (32) +
	 * 64-char token hash is 96 chars, but oc_file_locks.key is VARCHAR(64) (MySQL
	 * truncates, strict/Postgres rejects), so md5() of the token hash keeps it at 64.
	 * The key is a serialization token, not a secret, so a fast hash is fine.
	 */
	private function claimLockKey(PublicWorkflow $workflow): string {
		return self::CLAIM_LOCK_PREFIX . md5($workflow->getTokenHash());
	}

	/**
	 * Reserve the workflow for the claimant under a pessimistic row lock: flip to
	 * CLAIMING and stamp claimed_by_uid + claim_started_at. Serialises concurrent
	 * claims and is idempotent for the same claimant. Only the claimant that started
	 * a CLAIMING row may resume it; a different user never takes it over.
	 */
	public function beginClaim(PublicWorkflow $workflow, IUser $claimant): PublicWorkflow {
		$this->connection->beginTransaction();
		try {
			$locked = $this->mapper->findByTokenHashForUpdate($workflow->getTokenHash());
			if ($locked === null) {
				throw new LibresignException('Invalid workflow');
			}
			if (!$this->hasDocument($locked)) {
				throw new LibresignException('Upload a document first');
			}
			$owner = $this->userManager->get($locked->getOwnerUid());
			if (!$owner instanceof IUser || !$this->accountService->isTemporaryUser($owner)) {
				throw new LibresignException('Invalid workflow');
			}

			$uid = $claimant->getUID();
			$status = $locked->getStatus();

			if ($status === PublicWorkflow::STATUS_CLAIMED) {
				if ($locked->getClaimedByUid() === $uid) {
					$this->connection->commit();
					return $locked; // idempotent replay
				}
				throw new LibresignException('This document has already been claimed');
			}
			if ($status === PublicWorkflow::STATUS_REVOKED) {
				throw new LibresignException('Invalid workflow');
			}
			if ($status === PublicWorkflow::STATUS_ACTIVE && $this->isExpired($locked)) {
				throw new LibresignException('Invalid workflow');
			}
			if ($status === PublicWorkflow::STATUS_CLAIMING) {
				$claimedBy = $locked->getClaimedByUid();
				// Only the same claimant may resume an interrupted claim, however old; a
				// different user must start fresh from an ACTIVE workflow.
				if ($claimedBy !== null && $claimedBy !== $uid) {
					throw new LibresignException('This document is being claimed by another account');
				}
			}

			$now = $this->timeFactory->getDateTime();
			$locked->setStatus(PublicWorkflow::STATUS_CLAIMING);
			$locked->setClaimedByUid($uid);
			$locked->setClaimStartedAt($now);
			$locked->setUpdatedAt($now);
			$this->mapper->update($locked);
			$this->connection->commit();
			return $locked;
		} catch (\Throwable $e) {
			$this->connection->rollBack();
			throw $e;
		}
	}

	/**
	 * Copy the workflow's physical document(s) into the claimant's LibreSign folder
	 * and re-point the File record (user_id + node ids). Mirrors EnvelopeFileRelocator
	 * (copy into the destination, not a cross-storage move) and keeps the same File
	 * row id and UUID, so sign requests, visible elements and sponsorship (keyed by
	 * file_id) are preserved. Idempotent: a no-op once File.user_id is the claimant.
	 */
	public function transferDocumentOwnership(PublicWorkflow $workflow, IUser $claimant): File {
		$fileUuid = (string)$workflow->getFileUuid();
		$file = $this->fileMapper->getByUuid($fileUuid);
		// Fast path: a fully completed transfer (the temp owner may already be
		// gone, so do not try to resolve the source again).
		if ($file->getUserId() === $claimant->getUID()) {
			return $file;
		}

		$owner = $this->resolveOwner($workflow);
		// Resolve the source node(s) from the temporary owner's storage. These
		// still exist here because the temp user is only deleted in completeClaim,
		// after a verified transfer.
		$this->folderService->setUserId($owner->getUID());
		$sourceNode = $this->folderService->getFileByNodeId((int)$file->getNodeId());
		$signedSource = $file->getSignedNodeId() !== null
			? $this->folderService->getFileByNodeId((int)$file->getSignedNodeId())
			: null;

		// Deterministic destination container for this workflow's document, keyed by
		// the file UUID: a retry after a failed DB update adopts the prior copy here
		// instead of making a second one.
		$destFolder = $this->claimDestinationFolder($claimant, $fileUuid);

		[$destNode, $createdMain] = $this->adoptOrCopyInto($destFolder, $sourceNode);
		$signedDest = null;
		$createdSigned = false;
		if ($signedSource !== null) {
			[$signedDest, $createdSigned] = $this->adoptOrCopyInto($destFolder, $signedSource);
		}

		// Re-validate immediately before the authoritative DB write: confirm this
		// claimant still holds a CLAIMING workflow, so a lost race can never clobber
		// the File row.
		$fresh = $this->mapper->findByTokenHash($workflow->getTokenHash());
		if ($fresh === null
			|| $fresh->getStatus() !== PublicWorkflow::STATUS_CLAIMING
			|| $fresh->getClaimedByUid() !== $claimant->getUID()) {
			// Only compensate copies this call created; never a prior/adopted copy.
			if ($createdMain) {
				$this->safeDeleteNode($destNode);
			}
			if ($createdSigned && $signedDest !== null) {
				$this->safeDeleteNode($signedDest);
			}
			throw new LibresignException('This document is being claimed by another account');
		}

		$file->setUserId($claimant->getUID());
		$file->setNodeId($destNode->getId());
		if ($signedDest !== null) {
			$file->setSignedNodeId($signedDest->getId());
		}
		try {
			$this->fileMapper->update($file);
		} catch (\Throwable $e) {
			// Compensate ONLY copies created this call. An adopted copy belongs to a
			// prior attempt, and the deterministic folder lets the next retry re-adopt
			// it rather than duplicate — so a failed compensation is self-healing.
			if ($createdMain) {
				$this->safeDeleteNode($destNode);
			}
			if ($createdSigned && $signedDest !== null) {
				$this->safeDeleteNode($signedDest);
			}
			throw $e;
		}
		return $file;
	}

	/**
	 * Resolve/create the deterministic, workflow-scoped destination folder
	 * (<LibreSign>/claimed-<fileUuid>). The physical path is irrelevant to
	 * resolution (the authenticated flow finds the document by node id); the folder
	 * only gives the transfer a stable, collision-free identity for idempotent retries.
	 */
	private function claimDestinationFolder(IUser $claimant, string $fileUuid): \OCP\Files\Folder {
		$this->folderService->setUserId($claimant->getUID());
		$base = $this->folderService->getFolder();
		$name = 'claimed-' . $fileUuid;
		try {
			$node = $base->get($name);
		} catch (\OCP\Files\NotFoundException) {
			$node = $base->newFolder($name);
		}
		if (!$node instanceof \OCP\Files\Folder) {
			throw new LibresignException('Could not prepare the claimed document folder');
		}
		return $node;
	}

	/**
	 * Adopt the prior copy if the workflow-scoped folder already holds a file with
	 * the source's name (a retry after a failed DB update), otherwise copy the source
	 * in. A same-name match is always this workflow's own copy.
	 *
	 * @return array{0: \OCP\Files\File, 1: bool} [node, createdThisCall]
	 */
	private function adoptOrCopyInto(\OCP\Files\Folder $destFolder, \OCP\Files\File $source): array {
		$filename = $source->getName();
		if ($destFolder->nodeExists($filename)) {
			$existing = $destFolder->get($filename);
			if (!$existing instanceof \OCP\Files\File) {
				throw new LibresignException('Unexpected node in the claimed document folder');
			}
			return [$existing, false];
		}
		$copy = $destFolder->newFile($filename, $source->getContent());
		if (!$copy instanceof \OCP\Files\File) {
			throw new LibresignException('Could not create the claimed document');
		}
		return [$copy, true];
	}

	private function safeDeleteNode(\OCP\Files\Node $node): void {
		try {
			$node->delete();
		} catch (\Throwable $e) {
			// Non-fatal: the deterministic folder means the next retry re-adopts it.
			$this->logger->warning('[PublicWorkflow] could not compensate claim copy', ['exception' => $e]);
		}
	}

	/**
	 * Finalise the claim: mark CLAIMED with timestamps, then delete the temporary
	 * owner LAST. Deletion is guarded so it refuses a temp user that still owns the
	 * claimed File row (so UserDeleted cleanup can never rewrite it to DELETED_USER).
	 * Deletion failure is non-fatal — the claim is already committed.
	 */
	public function completeClaim(PublicWorkflow $workflow, IUser $claimant): PublicWorkflow {
		// Verify the transfer actually landed on the claimant BEFORE finalising:
		// the File must be owned by the claimant and its node must resolve in the
		// claimant's storage. If not, leave the workflow CLAIMING (recoverable) and
		// do NOT delete the temp user or mark claimed.
		$file = $this->fileMapper->getByUuid((string)$workflow->getFileUuid());
		if ($file->getUserId() !== $claimant->getUID()
			|| $file->getNodeId() === null
			|| !$this->claimantCanResolveNode($claimant, (int)$file->getNodeId())) {
			throw new LibresignException('Ownership transfer could not be verified');
		}

		if ($workflow->getStatus() !== PublicWorkflow::STATUS_CLAIMED) {
			$now = $this->timeFactory->getDateTime();
			$workflow->setStatus(PublicWorkflow::STATUS_CLAIMED);
			$workflow->setClaimedAt($now);
			$workflow->setUpdatedAt($now);
			$this->mapper->update($workflow);
		}
		$this->cleanupTemporaryOwner($workflow);
		return $workflow;
	}

	private function claimantCanResolveNode(IUser $claimant, int $nodeId): bool {
		try {
			$this->folderService->setUserId($claimant->getUID());
			$this->folderService->getFileByNodeId($nodeId);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	private function cleanupTemporaryOwner(PublicWorkflow $workflow): void {
		try {
			$owner = $this->userManager->get($workflow->getOwnerUid());
			if (!$owner instanceof IUser || !$this->accountService->isTemporaryUser($owner)) {
				return;
			}
			// Load-bearing guard: never delete a temp owner that STILL owns the
			// claimed file (transfer not actually complete), which would let the
			// UserDeleted cleanup orphan it.
			$file = $this->fileMapper->getByUuid((string)$workflow->getFileUuid());
			if ($file->getUserId() === $owner->getUID()) {
				$this->logger->error('[PublicWorkflow] refusing to delete temp owner that still owns the claimed file', [
					'uid' => $owner->getUID(),
				]);
				return;
			}
			$this->accountService->deleteTemporaryUser($owner);
		} catch (\Throwable $e) {
			// Non-fatal: claim already committed; a reconcile job can retry.
			$this->logger->warning('[PublicWorkflow] temporary owner cleanup deferred', ['exception' => $e]);
		}
	}

	/**
	 * Enforce that every incoming public visible element belongs to this workflow's
	 * own document (Finding H). The file id is resolved server-side from the
	 * capability-bound file_uuid — never trusted from the request; any client
	 * fileId, signRequestId or elementId not belonging to this file is rejected, and
	 * fileId is forced to the resolved value. Returns the normalised elements.
	 */
	public function assertWorkflowElements(PublicWorkflow $workflow, array $visibleElements): array {
		if (empty($visibleElements)) {
			return $visibleElements;
		}
		$file = $this->fileMapper->getByUuid((string)$workflow->getFileUuid());
		$fileId = (int)$file->getId();
		$normalised = [];
		foreach ($visibleElements as $element) {
			if (isset($element['fileId']) && (int)$element['fileId'] !== $fileId) {
				throw new LibresignException('Invalid signature position');
			}
			if (isset($element['signRequestId'])) {
				try {
					$signRequest = $this->signRequestMapper->getById((int)$element['signRequestId']);
				} catch (\Throwable) {
					throw new LibresignException('Invalid signature position');
				}
				if ((int)$signRequest->getFileId() !== $fileId) {
					throw new LibresignException('Invalid signature position');
				}
			}
			if (isset($element['elementId'])) {
				try {
					$existing = $this->fileElementMapper->getById((int)$element['elementId']);
				} catch (\Throwable) {
					throw new LibresignException('Invalid signature position');
				}
				if ((int)$existing->getFileId() !== $fileId) {
					throw new LibresignException('Invalid signature position');
				}
			}
			$element['fileId'] = $fileId;
			$normalised[] = $element;
		}
		return $normalised;
	}

	/**
	 * Draft/capability lifetime in hours. Admin-configurable via
	 * public_workflow_expiry_hours; falls back to EXPIRY_HOURS and is floored at
	 * 1 hour so a misconfiguration can never expire drafts (and their cookies)
	 * instantly. The capability cookie's Max-Age is derived from the resulting
	 * expiresAt, so this one value governs both.
	 */
	private function expiryHours(): int {
		// Type-tolerant: a string-typed value must fall back to the default, not
		// throw. The admin endpoint repairs the stored type.
		try {
			return max(1, $this->appConfig->getValueInt(Application::APP_ID, 'public_workflow_expiry_hours', self::EXPIRY_HOURS));
		} catch (\Throwable) {
			return self::EXPIRY_HOURS;
		}
	}

	public function isExpired(PublicWorkflow $workflow): bool {
		return $workflow->getExpiresAt() <= $this->timeFactory->getDateTime();
	}

	private function hashToken(string $rawToken): string {
		return hash('sha256', $rawToken);
	}

	private function isWellFormedToken(string $rawToken): bool {
		// 32 bytes hex-encoded == 64 lowercase hex chars.
		return strlen($rawToken) === self::HASH_HEX_LENGTH && ctype_xdigit($rawToken);
	}

	private function compensateOwner(IUser $owner): void {
		try {
			$this->accountService->deleteTemporaryUser($owner);
		} catch (\Throwable $e) {
			// Residual risk: an orphan temporary user may remain. Surfaced here;
			// there is no authorizable workflow row pointing at it.
			$this->logger->error('[PublicWorkflow] Compensation failed: orphan temporary user may remain', [
				'uid' => $owner->getUID(),
				'exception' => $e,
			]);
		}
	}
}
