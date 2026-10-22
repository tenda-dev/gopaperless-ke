<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Db\File;
use OCA\Libresign\Db\FileElement;
use OCA\Libresign\Db\FileElementMapper;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\PublicWorkflow;
use OCA\Libresign\Db\PublicWorkflowMapper;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\AccountService;
use OCA\Libresign\Service\FileElementService;
use OCA\Libresign\Service\FolderService;
use OCA\Libresign\Service\PublicWorkflowService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Security-core coverage for the public-workflow capability lifecycle:
 * token hashing, owner provisioning + compensation, resolution, expiry,
 * revocation and the one-document constraint.
 */
final class PublicWorkflowServiceTest extends TestCase {
	private IDBConnection&MockObject $connection;
	private PublicWorkflowMapper&MockObject $mapper;
	private AccountService&MockObject $accountService;
	private FileMapper&MockObject $fileMapper;
	private FileElementMapper&MockObject $fileElementMapper;
	private SignRequestMapper&MockObject $signRequestMapper;
	private FileElementService&MockObject $fileElementService;
	private FolderService&MockObject $folderService;
	private ILockingProvider&MockObject $lockingProvider;
	private IUserManager&MockObject $userManager;
	private ITimeFactory&MockObject $timeFactory;
	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;
	private PublicWorkflowService $service;

	public function setUp(): void {
		parent::setUp();
		$this->connection = $this->createMock(IDBConnection::class);
		$this->mapper = $this->createMock(PublicWorkflowMapper::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->fileMapper = $this->createMock(FileMapper::class);
		$this->fileElementMapper = $this->createMock(FileElementMapper::class);
		$this->signRequestMapper = $this->createMock(SignRequestMapper::class);
		$this->fileElementService = $this->createMock(FileElementService::class);
		$this->folderService = $this->createMock(FolderService::class);
		$this->lockingProvider = $this->createMock(ILockingProvider::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->timeFactory->method('getDateTime')->willReturnCallback(
			fn () => new \DateTime('2026-10-16T12:00:00+00:00')
		);
		// Unconfigured: return each caller's default, so expiry stays the 1h default.
		$this->appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => $default
		);
		$this->service = new PublicWorkflowService(
			$this->connection,
			$this->mapper,
			$this->accountService,
			$this->fileMapper,
			$this->fileElementMapper,
			$this->signRequestMapper,
			$this->fileElementService,
			$this->folderService,
			$this->lockingProvider,
			$this->userManager,
			$this->timeFactory,
			$this->appConfig,
			$this->logger,
		);
	}

	private function tempUser(string $uid = 'gopaperless_public_abc'): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	public function testCreatePersistsOnlyHashAndReturnsRawToken(): void {
		$owner = $this->tempUser();
		$this->accountService->expects($this->once())
			->method('createTemporaryUser')->willReturn($owner);
		$this->connection->expects($this->once())->method('beginTransaction');
		$this->connection->expects($this->once())->method('commit');
		$this->accountService->expects($this->never())->method('deleteTemporaryUser');

		$captured = null;
		$this->mapper->expects($this->once())->method('insert')
			->willReturnCallback(function (PublicWorkflow $w) use (&$captured) {
				$captured = $w;
				return $w;
			});

		$result = $this->service->create();

		$raw = $result['rawToken'];
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $raw);
		// The DB stores only the hash; it must differ from the raw token and be
		// exactly sha256(raw).
		$this->assertNotSame($raw, $captured->getTokenHash());
		$this->assertSame(hash('sha256', $raw), $captured->getTokenHash());
		$this->assertSame('gopaperless_public_abc', $captured->getOwnerUid());
		$this->assertSame(PublicWorkflow::STATUS_ACTIVE, $captured->getStatus());
	}

	public function testCreateCompensatesOwnerWhenInsertFails(): void {
		$owner = $this->tempUser();
		$this->accountService->method('createTemporaryUser')->willReturn($owner);
		$this->connection->expects($this->once())->method('beginTransaction');
		$this->connection->expects($this->once())->method('rollBack');
		$this->mapper->method('insert')->willThrowException(new \RuntimeException('db down'));
		// Compensation: the orphan temporary owner must be deleted.
		$this->accountService->expects($this->once())
			->method('deleteTemporaryUser')->with($owner);

		$this->expectException(LibresignException::class);
		$this->service->create();
	}

	private function activeWorkflow(string $raw, string $ownerUid = 'gopaperless_public_abc'): PublicWorkflow {
		$w = new PublicWorkflow();
		$w->setTokenHash(hash('sha256', $raw));
		$w->setOwnerUid($ownerUid);
		$w->setStatus(PublicWorkflow::STATUS_ACTIVE);
		$w->setCreatedAt(new \DateTime('2026-10-16T11:00:00+00:00'));
		$w->setExpiresAt(new \DateTime('2026-10-19T11:00:00+00:00')); // future vs mocked now
		return $w;
	}

	public function testResolveRejectsMalformedToken(): void {
		$this->mapper->expects($this->never())->method('findByTokenHash');
		$this->expectException(LibresignException::class);
		$this->service->resolveFromToken('not-a-valid-hex-token');
	}

	public function testResolveRejectsMissingToken(): void {
		$this->expectException(LibresignException::class);
		$this->service->resolveFromToken(null);
	}

	public function testResolveRejectsUnknownToken(): void {
		$raw = str_repeat('a', 64);
		$this->mapper->method('findByTokenHash')->willReturn(null);
		$this->expectException(LibresignException::class);
		$this->service->resolveFromToken($raw);
	}

	public function testResolveRejectsRevoked(): void {
		$raw = str_repeat('b', 64);
		$w = $this->activeWorkflow($raw);
		$w->setStatus(PublicWorkflow::STATUS_REVOKED);
		$this->mapper->method('findByTokenHash')->willReturn($w);
		$this->expectException(LibresignException::class);
		$this->service->resolveFromToken($raw);
	}

	public function testResolveRejectsExpired(): void {
		$raw = str_repeat('c', 64);
		$w = $this->activeWorkflow($raw);
		$w->setExpiresAt(new \DateTime('2026-10-16T11:59:00+00:00')); // before mocked now 12:00
		$this->mapper->method('findByTokenHash')->willReturn($w);
		$this->expectException(LibresignException::class);
		$this->service->resolveFromToken($raw);
	}

	public function testResolveRejectsWhenOwnerMissing(): void {
		$raw = str_repeat('d', 64);
		$this->mapper->method('findByTokenHash')->willReturn($this->activeWorkflow($raw));
		$this->userManager->method('get')->willReturn(null);
		$this->expectException(LibresignException::class);
		$this->service->resolveFromToken($raw);
	}

	public function testResolveRejectsWhenOwnerNotTemporary(): void {
		$raw = str_repeat('e', 64);
		$this->mapper->method('findByTokenHash')->willReturn($this->activeWorkflow($raw));
		$owner = $this->tempUser();
		$this->userManager->method('get')->willReturn($owner);
		$this->accountService->method('isTemporaryUser')->with($owner)->willReturn(false);
		$this->expectException(LibresignException::class);
		$this->service->resolveFromToken($raw);
	}

	public function testResolveSucceedsForValidWorkflow(): void {
		$raw = str_repeat('f', 64);
		$workflow = $this->activeWorkflow($raw);
		$this->mapper->method('findByTokenHash')->willReturn($workflow);
		$owner = $this->tempUser();
		$this->userManager->method('get')->with('gopaperless_public_abc')->willReturn($owner);
		$this->accountService->method('isTemporaryUser')->with($owner)->willReturn(true);

		$resolved = $this->service->resolveFromToken($raw);
		$this->assertSame($workflow, $resolved);
		// Owner comes from the row, never from the caller.
		$this->assertSame('gopaperless_public_abc', $resolved->getOwnerUid());
	}

	public function testAttachDocumentEnforcesOneDocument(): void {
		$w = $this->activeWorkflow(str_repeat('a', 64));
		$this->mapper->expects($this->once())->method('update');
		$this->service->attachDocument($w, 'file-uuid-1');
		$this->assertTrue($this->service->hasDocument($w));

		// Second upload attempt must be rejected by the backend.
		$this->expectException(LibresignException::class);
		$this->service->attachDocument($w, 'file-uuid-2');
	}

	/**
	 * Flow-level coverage of the locked critical section. NOTE: these use mocked
	 * collaborators and run sequentially — they prove the control flow (lock →
	 * re-check → create → attach → commit, rollback on failure), NOT real
	 * database-level concurrency. The row lock only serialises competing requests
	 * on MariaDB/MySQL at runtime (forUpdate()); that is a staging gate, not
	 * provable here.
	 */
	public function testCreateDocumentClaimsSlotUnderLockAndCommits(): void {
		$locked = $this->activeWorkflow(str_repeat('a', 64)); // no file_uuid yet
		$this->mapper->expects($this->once())
			->method('findByTokenHashForUpdate')->willReturn($locked);
		$owner = $this->tempUser();
		$this->userManager->method('get')->willReturn($owner);
		$this->connection->expects($this->once())->method('beginTransaction');
		$this->connection->expects($this->once())->method('commit');
		$this->connection->expects($this->never())->method('rollBack');
		$this->mapper->expects($this->once())->method('update');

		$file = new File();
		$file->setUuid('new-file-uuid');
		$file->setUserId('gopaperless_public_abc');

		$returned = $this->service->createDocument(
			$this->activeWorkflow(str_repeat('a', 64)),
			fn (\OCP\IUser $o) => $file,
		);
		$this->assertSame('new-file-uuid', $returned->getUuid());
		$this->assertSame('new-file-uuid', $locked->getFileUuid());
	}

	public function testCreateDocumentRejectsSecondClaimUnderLockWithoutCreatingFile(): void {
		// The locked row already has a document → the second claimant must be
		// rejected and must NOT run the file-creation closure.
		$locked = $this->activeWorkflow(str_repeat('b', 64));
		$locked->setFileUuid('already-attached');
		$this->mapper->method('findByTokenHashForUpdate')->willReturn($locked);
		$this->connection->expects($this->once())->method('beginTransaction');
		$this->connection->expects($this->once())->method('rollBack');
		$this->connection->expects($this->never())->method('commit');

		$createRan = false;
		$this->expectException(LibresignException::class);
		try {
			$this->service->createDocument(
				$this->activeWorkflow(str_repeat('b', 64)),
				function (\OCP\IUser $o) use (&$createRan) {
					$createRan = true;
					return new File();
				},
			);
		} finally {
			$this->assertFalse($createRan, 'file creation must not run when the slot is already claimed');
		}
	}

	public function testCreateDocumentRollsBackWhenAttachFails(): void {
		$locked = $this->activeWorkflow(str_repeat('c', 64));
		$this->mapper->method('findByTokenHashForUpdate')->willReturn($locked);
		$this->userManager->method('get')->willReturn($this->tempUser());
		$this->connection->expects($this->once())->method('beginTransaction');
		$this->connection->expects($this->once())->method('rollBack');
		$this->connection->expects($this->never())->method('commit');
		// Attach (the UPDATE after file creation) fails → rollback, error propagates.
		$this->mapper->method('update')->willThrowException(new \RuntimeException('db down'));

		$file = new File();
		$file->setUuid('created-but-unattached');

		$this->expectException(\RuntimeException::class);
		$this->service->createDocument(
			$this->activeWorkflow(str_repeat('c', 64)),
			fn (\OCP\IUser $o) => $file,
		);
	}
	private function workflowWithDoc(string $uid = 'gopaperless_public_abc', string $uuid = 'file-uuid'): PublicWorkflow {
		$w = new PublicWorkflow();
		$w->setOwnerUid($uid);
		$w->setFileUuid($uuid);
		return $w;
	}

	public function testDeleteVisibleElementRejectsWhenNoDocument(): void {
		$this->fileElementService->expects($this->never())->method('deleteVisibleElement');
		$this->expectException(LibresignException::class);
		$this->service->deleteVisibleElement(new PublicWorkflow(), 5);
	}

	public function testDeleteVisibleElementRejectsWhenOwnerMismatch(): void {
		$w = $this->workflowWithDoc();
		$this->userManager->method('get')->with('gopaperless_public_abc')->willReturn($this->tempUser());
		$file = $this->createMock(File::class);
		$file->method('getUserId')->willReturn('someone_else');
		$this->fileMapper->method('getByUuid')->with('file-uuid')->willReturn($file);
		$this->fileElementService->expects($this->never())->method('deleteVisibleElement');
		$this->expectException(LibresignException::class);
		$this->service->deleteVisibleElement($w, 5);
	}

	public function testDeleteVisibleElementRejectsForeignElement(): void {
		$w = $this->workflowWithDoc();
		$this->userManager->method('get')->willReturn($this->tempUser());
		$file = $this->createMock(File::class);
		$file->method('getUserId')->willReturn('gopaperless_public_abc');
		$file->method('getId')->willReturn(1);
		$this->fileMapper->method('getByUuid')->willReturn($file);
		$foreign = $this->createMock(FileElement::class);
		$foreign->method('getFileId')->willReturn(2);
		$this->fileElementMapper->method('getById')->with(5)->willReturn($foreign);
		$this->fileElementService->expects($this->never())->method('deleteVisibleElement');
		$this->expectException(LibresignException::class);
		$this->service->deleteVisibleElement($w, 5);
	}

	public function testDeleteVisibleElementRejectsMissingElement(): void {
		$w = $this->workflowWithDoc();
		$this->userManager->method('get')->willReturn($this->tempUser());
		$file = $this->createMock(File::class);
		$file->method('getUserId')->willReturn('gopaperless_public_abc');
		$file->method('getId')->willReturn(1);
		$this->fileMapper->method('getByUuid')->willReturn($file);
		$this->fileElementMapper->method('getById')->willThrowException(new DoesNotExistException('nope'));
		$this->fileElementService->expects($this->never())->method('deleteVisibleElement');
		$this->expectException(LibresignException::class);
		$this->service->deleteVisibleElement($w, 999);
	}

	public function testDeleteVisibleElementDeletesOwnedElement(): void {
		$w = $this->workflowWithDoc();
		$this->userManager->method('get')->willReturn($this->tempUser());
		$file = $this->createMock(File::class);
		$file->method('getUserId')->willReturn('gopaperless_public_abc');
		$file->method('getId')->willReturn(1);
		$this->fileMapper->method('getByUuid')->willReturn($file);
		$element = $this->createMock(FileElement::class);
		$element->method('getFileId')->willReturn(1);
		$this->fileElementMapper->method('getById')->with(7)->willReturn($element);
		$this->fileElementService->expects($this->once())->method('deleteVisibleElement')->with(7);
		$this->service->deleteVisibleElement($w, 7);
	}


	// --- claim lifecycle (Step 2) ---

	private function claimant(string $uid = 'alice'): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	public function testBeginClaimReservesActiveWorkflowForClaimant(): void {
		$locked = $this->workflowWithDoc();
		$this->mapper->method('findByTokenHashForUpdate')->willReturn($locked);
		$this->userManager->method('get')->willReturn($this->tempUser());
		$this->accountService->method('isTemporaryUser')->willReturn(true);
		$this->connection->expects($this->once())->method('beginTransaction');
		$this->connection->expects($this->once())->method('commit');
		$this->connection->expects($this->never())->method('rollBack');
		$this->mapper->expects($this->once())->method('update');

		$result = $this->service->beginClaim($this->workflowWithDoc(), $this->claimant('alice'));

		$this->assertSame(PublicWorkflow::STATUS_CLAIMING, $result->getStatus());
		$this->assertSame('alice', $result->getClaimedByUid());
		$this->assertNotNull($result->getClaimStartedAt());
	}

	public function testBeginClaimIsIdempotentForSameClaimant(): void {
		$locked = $this->workflowWithDoc();
		$locked->setStatus(PublicWorkflow::STATUS_CLAIMED);
		$locked->setClaimedByUid('alice');
		$this->mapper->method('findByTokenHashForUpdate')->willReturn($locked);
		$this->userManager->method('get')->willReturn($this->tempUser());
		$this->accountService->method('isTemporaryUser')->willReturn(true);
		$this->connection->expects($this->once())->method('commit');
		$this->mapper->expects($this->never())->method('update');

		$result = $this->service->beginClaim($this->workflowWithDoc(), $this->claimant('alice'));
		$this->assertSame(PublicWorkflow::STATUS_CLAIMED, $result->getStatus());
	}

	public function testBeginClaimRejectsWorkflowClaimedByAnotherAccount(): void {
		$locked = $this->workflowWithDoc();
		$locked->setStatus(PublicWorkflow::STATUS_CLAIMED);
		$locked->setClaimedByUid('bob');
		$this->mapper->method('findByTokenHashForUpdate')->willReturn($locked);
		$this->userManager->method('get')->willReturn($this->tempUser());
		$this->accountService->method('isTemporaryUser')->willReturn(true);
		$this->connection->expects($this->once())->method('rollBack');
		$this->connection->expects($this->never())->method('commit');

		$this->expectException(LibresignException::class);
		$this->service->beginClaim($this->workflowWithDoc(), $this->claimant('alice'));
	}

	public function testBeginClaimRejectsAnotherClaimantEvenWhenStale(): void {
		$locked = $this->workflowWithDoc();
		$locked->setStatus(PublicWorkflow::STATUS_CLAIMING);
		$locked->setClaimedByUid('bob');
		$locked->setClaimStartedAt(new \DateTime('2026-10-16T11:00:00+00:00')); // 1h ago (stale) — still no takeover
		$this->mapper->method('findByTokenHashForUpdate')->willReturn($locked);
		$this->userManager->method('get')->willReturn($this->tempUser());
		$this->accountService->method('isTemporaryUser')->willReturn(true);
		$this->connection->expects($this->once())->method('rollBack');

		$this->expectException(LibresignException::class);
		$this->service->beginClaim($this->workflowWithDoc(), $this->claimant('alice'));
	}

	public function testTransferIsIdempotentWhenFileAlreadyOwnedByClaimant(): void {
		$file = new File();
		$file->setUuid('file-uuid');
		$file->setUserId('alice');
		$this->fileMapper->method('getByUuid')->with('file-uuid')->willReturn($file);
		// Already transferred → the folder service is never touched.
		$this->folderService->expects($this->never())->method('setUserId');

		$returned = $this->service->transferDocumentOwnership($this->workflowWithDoc(), $this->claimant('alice'));
		$this->assertSame('file-uuid', $returned->getUuid());
	}

	public function testCompleteClaimRefusesToFinaliseWhenFileNotOwnedByClaimant(): void {
		$workflow = $this->workflowWithDoc();
		$workflow->setStatus(PublicWorkflow::STATUS_CLAIMING);
		// File still owned by the temp user → transfer not verified → must NOT mark
		// claimed and must NOT delete the temp user.
		$file = new File();
		$file->setUuid('file-uuid');
		$file->setUserId('gopaperless_public_abc');
		$file->setNodeId(42);
		$this->fileMapper->method('getByUuid')->willReturn($file);
		$this->accountService->expects($this->never())->method('deleteTemporaryUser');

		$this->expectException(LibresignException::class);
		try {
			$this->service->completeClaim($workflow, $this->claimant('alice'));
		} finally {
			$this->assertSame(PublicWorkflow::STATUS_CLAIMING, $workflow->getStatus());
			$this->assertNull($workflow->getClaimedAt());
		}
	}

	public function testCompleteClaimDeletesTempOwnerOnlyAfterVerifiedTransfer(): void {
		$workflow = $this->workflowWithDoc();
		$workflow->setStatus(PublicWorkflow::STATUS_CLAIMING);
		$owner = $this->tempUser();
		$this->userManager->method('get')->willReturn($owner);
		$this->accountService->method('isTemporaryUser')->willReturn(true);
		// File reassigned to the claimant AND its node resolves in the claimant's
		// storage → transfer verified → safe to finalise and delete the temp owner.
		$file = new File();
		$file->setUuid('file-uuid');
		$file->setUserId('alice');
		$file->setNodeId(42);
		$this->fileMapper->method('getByUuid')->willReturn($file);
		$this->folderService->method('getFileByNodeId')->willReturn($this->createMock(\OCP\Files\File::class));
		$this->accountService->expects($this->once())->method('deleteTemporaryUser')->with($owner);

		$result = $this->service->completeClaim($workflow, $this->claimant('alice'));
		$this->assertSame(PublicWorkflow::STATUS_CLAIMED, $result->getStatus());
		$this->assertNotNull($result->getClaimedAt());
	}

	public function testResolveForClaimRejectsRevoked(): void {
		$raw = str_repeat('d', 64);
		$w = $this->workflowWithDoc();
		$w->setTokenHash(hash('sha256', $raw));
		$w->setStatus(PublicWorkflow::STATUS_REVOKED);
		$this->mapper->method('findByTokenHash')->willReturn($w);

		$this->expectException(LibresignException::class);
		$this->service->resolveForClaim($raw);
	}

	public function testSaveRequesterIdentityNormalisesEmptyEmailToNull(): void {
		$workflow = $this->workflowWithDoc();
		$this->mapper->expects($this->once())->method('update');
		$this->service->saveRequesterIdentity($workflow, '   ', true);
		$this->assertNull($workflow->getRequesterEmail());
		$this->assertTrue($workflow->getRequesterIsSigner());
	}

	// --- claim retry / concurrency hardening (C1-C5) ---

	private function claimingFresh(string $uid = 'alice'): PublicWorkflow {
		$w = $this->workflowWithDoc();
		$w->setStatus(PublicWorkflow::STATUS_CLAIMING);
		$w->setClaimedByUid($uid);
		return $w;
	}

	public function testTransferAdoptsExistingCopyOnRetry(): void {
		// Retry after a failed DB update: the deterministic folder already holds the
		// copy, so it is ADOPTED (no second copy) and the row is re-pointed.
		$file = new File();
		$file->setUuid('file-uuid');
		$file->setUserId('gopaperless_public_abc');
		$file->setNodeId(10);
		$this->fileMapper->method('getByUuid')->willReturn($file);
		$this->userManager->method('get')->willReturn($this->tempUser());

		$source = $this->createMock(\OCP\Files\File::class);
		$source->method('getName')->willReturn('doc.pdf');
		$this->folderService->method('getFileByNodeId')->willReturn($source);

		$existing = $this->createMock(\OCP\Files\File::class);
		$existing->method('getId')->willReturn(99);
		$destFolder = $this->createMock(\OCP\Files\Folder::class);
		$destFolder->method('nodeExists')->willReturn(true);
		$destFolder->method('get')->willReturn($existing);
		$destFolder->expects($this->never())->method('newFile');

		$base = $this->createMock(\OCP\Files\Folder::class);
		$base->method('get')->willReturn($destFolder);
		$this->folderService->method('getFolder')->willReturn($base);
		$this->mapper->method('findByTokenHash')->willReturn($this->claimingFresh('alice'));
		$this->fileMapper->expects($this->once())->method('update');

		$returned = $this->service->transferDocumentOwnership($this->workflowWithDoc(), $this->claimant('alice'));
		$this->assertSame('alice', $returned->getUserId());
		$this->assertSame(99, $returned->getNodeId());
	}

	public function testTransferCompensatesCreatedCopyWhenUpdateFails(): void {
		$file = new File();
		$file->setUuid('file-uuid');
		$file->setUserId('gopaperless_public_abc');
		$file->setNodeId(10);
		$this->fileMapper->method('getByUuid')->willReturn($file);
		$this->userManager->method('get')->willReturn($this->tempUser());

		$source = $this->createMock(\OCP\Files\File::class);
		$source->method('getName')->willReturn('doc.pdf');
		$source->method('getContent')->willReturn('PDFBYTES');
		$this->folderService->method('getFileByNodeId')->willReturn($source);

		$newNode = $this->createMock(\OCP\Files\File::class);
		$newNode->method('getId')->willReturn(99);
		$newNode->expects($this->once())->method('delete'); // compensation
		$destFolder = $this->createMock(\OCP\Files\Folder::class);
		$destFolder->method('nodeExists')->willReturn(false);
		$destFolder->method('newFile')->willReturn($newNode);

		$base = $this->createMock(\OCP\Files\Folder::class);
		$base->method('get')->willReturn($destFolder);
		$this->folderService->method('getFolder')->willReturn($base);
		$this->mapper->method('findByTokenHash')->willReturn($this->claimingFresh('alice'));
		$this->fileMapper->method('update')->willThrowException(new \RuntimeException('db down'));

		$this->expectException(\RuntimeException::class);
		$this->service->transferDocumentOwnership($this->workflowWithDoc(), $this->claimant('alice'));
	}

	public function testTransferDoesNotDeleteAdoptedCopyWhenUpdateFails(): void {
		$file = new File();
		$file->setUuid('file-uuid');
		$file->setUserId('gopaperless_public_abc');
		$file->setNodeId(10);
		$this->fileMapper->method('getByUuid')->willReturn($file);
		$this->userManager->method('get')->willReturn($this->tempUser());

		$source = $this->createMock(\OCP\Files\File::class);
		$source->method('getName')->willReturn('doc.pdf');
		$this->folderService->method('getFileByNodeId')->willReturn($source);

		$existing = $this->createMock(\OCP\Files\File::class);
		$existing->method('getId')->willReturn(99);
		$existing->expects($this->never())->method('delete'); // adopted -> never compensated
		$destFolder = $this->createMock(\OCP\Files\Folder::class);
		$destFolder->method('nodeExists')->willReturn(true);
		$destFolder->method('get')->willReturn($existing);

		$base = $this->createMock(\OCP\Files\Folder::class);
		$base->method('get')->willReturn($destFolder);
		$this->folderService->method('getFolder')->willReturn($base);
		$this->mapper->method('findByTokenHash')->willReturn($this->claimingFresh('alice'));
		$this->fileMapper->method('update')->willThrowException(new \RuntimeException('db down'));

		$this->expectException(\RuntimeException::class);
		$this->service->transferDocumentOwnership($this->workflowWithDoc(), $this->claimant('alice'));
	}

	public function testClaimReturnsConflictWhenLockIsHeld(): void {
		$this->lockingProvider->method('acquireLock')->willThrowException(new LockedException('locked'));
		// A competing claim must not even enter beginClaim.
		$this->mapper->expects($this->never())->method('findByTokenHashForUpdate');
		$this->expectException(LibresignException::class);
		$this->service->claim($this->workflowWithDoc(), $this->claimant('alice'));
	}

	public function testClaimLockHeldStillConflictsForADifferentClaimant(): void {
		// Lock held + the CLAIMING row belongs to someone else -> clean conflict,
		// never the same-claimant recovery path.
		$this->lockingProvider->method('acquireLock')->willThrowException(new LockedException('locked'));
		$w = $this->workflowWithDoc();
		$w->setStatus(PublicWorkflow::STATUS_CLAIMING);
		$w->setClaimedByUid('bob');
		$this->mapper->method('findByTokenHash')->willReturn($w);
		$this->mapper->expects($this->never())->method('findByTokenHashForUpdate');

		$this->expectException(LibresignException::class);
		$this->service->claim($this->workflowWithDoc(), $this->claimant('alice'));
	}

	public function testClaimLockHeldStillConflictsForARecentSameClaimant(): void {
		// Lock held + OUR OWN CLAIMING row but it is RECENT (possibly a live
		// concurrent transfer) -> still conflicts; recovery only kicks in once stale.
		$this->lockingProvider->method('acquireLock')->willThrowException(new LockedException('locked'));
		$now = 1_760_000_000;
		$this->timeFactory->method('getTime')->willReturn($now);
		$w = $this->workflowWithDoc();
		$w->setStatus(PublicWorkflow::STATUS_CLAIMING);
		$w->setClaimedByUid('alice');
		$w->setClaimStartedAt((new \DateTime())->setTimestamp($now - 2)); // 2s ago, not stale
		$this->mapper->method('findByTokenHash')->willReturn($w);
		$this->mapper->expects($this->never())->method('findByTokenHashForUpdate');

		$this->expectException(LibresignException::class);
		$this->service->claim($this->workflowWithDoc(), $this->claimant('alice'));
	}

	public function testCompleteClaimRefusesWhenNodeDoesNotResolveForClaimant(): void {
		$workflow = $this->workflowWithDoc();
		$workflow->setStatus(PublicWorkflow::STATUS_CLAIMING);
		$file = new File();
		$file->setUuid('file-uuid');
		$file->setUserId('alice'); // owned, but node is missing in the claimant storage
		$file->setNodeId(42);
		$this->fileMapper->method('getByUuid')->willReturn($file);
		$this->folderService->method('getFileByNodeId')->willThrowException(new \OCP\Files\NotFoundException());
		$this->accountService->expects($this->never())->method('deleteTemporaryUser');

		$this->expectException(LibresignException::class);
		$this->service->completeClaim($workflow, $this->claimant('alice'));
	}

	public function testCleanupFailureDoesNotFailAnOtherwiseValidClaim(): void {
		$workflow = $this->workflowWithDoc();
		$workflow->setStatus(PublicWorkflow::STATUS_CLAIMING);
		$file = new File();
		$file->setUuid('file-uuid');
		$file->setUserId('alice');
		$file->setNodeId(42);
		$this->fileMapper->method('getByUuid')->willReturn($file);
		$this->folderService->method('getFileByNodeId')->willReturn($this->createMock(\OCP\Files\File::class));
		$this->userManager->method('get')->willReturn($this->tempUser());
		$this->accountService->method('isTemporaryUser')->willReturn(true);
		// Deletion of the temp user fails AFTER a verified transfer -> non-fatal.
		$this->accountService->method('deleteTemporaryUser')->willThrowException(new LibresignException('cannot delete'));

		$result = $this->service->completeClaim($workflow, $this->claimant('alice'));
		$this->assertSame(PublicWorkflow::STATUS_CLAIMED, $result->getStatus());
	}

	// --- Finding H: visible-element ownership guard ---

	public function testAssertWorkflowElementsRejectsForeignSignRequest(): void {
		$file = new File();
		$file->setId(124);
		$file->setUuid('file-uuid');
		$this->fileMapper->method('getByUuid')->willReturn($file);
		$foreign = $this->createMock(\OCA\Libresign\Db\SignRequest::class);
		$foreign->method('getFileId')->willReturn(999); // belongs to another document
		$this->signRequestMapper->method('getById')->willReturn($foreign);

		$this->expectException(LibresignException::class);
		$this->service->assertWorkflowElements($this->workflowWithDoc(), [['signRequestId' => 7]]);
	}

	public function testAssertWorkflowElementsForcesServerResolvedFileId(): void {
		$file = new File();
		$file->setId(124);
		$file->setUuid('file-uuid');
		$this->fileMapper->method('getByUuid')->willReturn($file);
		$ours = $this->createMock(\OCA\Libresign\Db\SignRequest::class);
		$ours->method('getFileId')->willReturn(124);
		$this->signRequestMapper->method('getById')->willReturn($ours);

		// A client-omitted fileId is forced to the capability-resolved file id.
		$out = $this->service->assertWorkflowElements($this->workflowWithDoc(), [['signRequestId' => 7]]);
		$this->assertSame(124, $out[0]['fileId']);
	}
}
