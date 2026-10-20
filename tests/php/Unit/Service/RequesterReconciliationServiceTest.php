<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Db\File;
use OCA\Libresign\Db\IdentifyMethod;
use OCA\Libresign\Db\IdentifyMethodMapper;
use OCA\Libresign\Db\PublicWorkflow;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\AccountService;
use OCA\Libresign\Service\TrustedOidcProviderPolicy;
use OCA\Libresign\Service\RequesterReconciliationService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\Accounts\IAccountManager;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\Config\IUserConfig;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class RequesterReconciliationServiceTest extends TestCase {
	private IDBConnection&MockObject $connection;
	private FileMapper&MockObject $fileMapper;
	private SignRequestMapper&MockObject $signRequestMapper;
	private IdentifyMethodMapper&MockObject $identifyMethodMapper;
	private IAccountManager&MockObject $accountManager;
	private TrustedOidcProviderPolicy&MockObject $trustPolicy;
	private IUserManager&MockObject $userManager;
	private IUserConfig&MockObject $userConfig;
	private LoggerInterface&MockObject $logger;
	private RequesterReconciliationService $service;

	public function setUp(): void {
		parent::setUp();
		$this->connection = $this->createMock(IDBConnection::class);
		$this->fileMapper = $this->createMock(FileMapper::class);
		$this->signRequestMapper = $this->createMock(SignRequestMapper::class);
		$this->identifyMethodMapper = $this->createMock(IdentifyMethodMapper::class);
		$this->accountManager = $this->createMock(IAccountManager::class);
		$this->trustPolicy = $this->createMock(TrustedOidcProviderPolicy::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userConfig = $this->createMock(IUserConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new RequesterReconciliationService(
			$this->connection,
			$this->fileMapper,
			$this->signRequestMapper,
			$this->identifyMethodMapper,
			$this->accountManager,
			$this->trustPolicy,
			$this->userManager,
			$this->userConfig,
			$this->logger,
		);
	}

	private function claimant(string $uid = 'alice', ?string $email = null): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($email);
		return $user;
	}

	/** Make the shared trust policy vouch (or not) for any claimant. */
	private function trust(bool $trusted): void {
		$this->trustPolicy->method('isTrustedOidcAccount')->willReturn($trusted);
	}

	private function workflow(bool $isSigner = true, ?string $email = 'req@example.com'): PublicWorkflow {
		$w = new PublicWorkflow();
		$w->setFileUuid('file-uuid');
		$w->setStatus(PublicWorkflow::STATUS_CLAIMED);
		$w->setRequesterIsSigner($isSigner);
		$w->setRequesterEmail($email);
		return $w;
	}

	private function ownedFile(string $uid = 'alice'): File {
		$file = new File();
		$file->setUuid('file-uuid');
		$file->setUserId($uid);
		return $file;
	}

	private function signRequest(int $id): SignRequest&MockObject {
		$sr = $this->createMock(SignRequest::class);
		$sr->method('getId')->willReturn($id);
		$sr->method('getSigned')->willReturn(null);
		return $sr;
	}

	private function emailMethod(int $signRequestId, string $email): IdentifyMethod {
		$m = new IdentifyMethod();
		$m->setSignRequestId($signRequestId);
		$m->setIdentifierKey('email');
		$m->setIdentifierValue($email);
		return $m;
	}

	private function account(string $uid, string $email): IUser&MockObject {
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		$u->method('getEMailAddress')->willReturn($email);
		return $u;
	}

	public function testNotRequestedWhenRequesterIsNotSigner(): void {
		$result = $this->service->reconcile($this->workflow(false), $this->claimant());
		$this->assertSame(RequesterReconciliationService::NOT_REQUESTED, $result);
	}

	public function testUnresolvedWhenNoRequesterEmail(): void {
		$result = $this->service->reconcile($this->workflow(true, null), $this->claimant());
		$this->assertSame(RequesterReconciliationService::UNRESOLVED_NO_EMAIL, $result);
	}

	public function testErrorWhenFileNotOwnedByClaimant(): void {
		$this->fileMapper->method('getByUuid')->willReturn($this->ownedFile('someone-else'));
		$result = $this->service->reconcile($this->workflow(), $this->claimant('alice'));
		$this->assertSame(RequesterReconciliationService::UNRESOLVED_ERROR, $result);
	}

	public function testAlreadyReconciledWhenAccountRequestExistsAndEmailGone(): void {
		$this->fileMapper->method('getByUuid')->willReturn($this->ownedFile());
		$this->signRequestMapper->method('getByIdentifierAndFileId')->willReturnCallback(
			fn (string $key) => $key === 'account' ? $this->signRequest(1) : null
		);
		$result = $this->service->reconcile($this->workflow(), $this->claimant());
		$this->assertSame(RequesterReconciliationService::ALREADY_RECONCILED, $result);
	}

	public function testConflictWhenDistinctAccountRequestExists(): void {
		$this->fileMapper->method('getByUuid')->willReturn($this->ownedFile());
		$this->signRequestMapper->method('getByIdentifierAndFileId')->willReturnCallback(
			fn (string $key) => $key === 'account' ? $this->signRequest(1) : $this->signRequest(2)
		);
		$result = $this->service->reconcile($this->workflow(), $this->claimant());
		$this->assertSame(RequesterReconciliationService::UNRESOLVED_CONFLICT, $result);
	}

	public function testUnresolvedWhenNoProvisionalRequest(): void {
		$this->fileMapper->method('getByUuid')->willReturn($this->ownedFile());
		$this->signRequestMapper->method('getByIdentifierAndFileId')->willReturn(null);
		$result = $this->service->reconcile($this->workflow(), $this->claimant());
		$this->assertSame(RequesterReconciliationService::UNRESOLVED_NO_REQUEST, $result);
	}

	public function testUntrustedAccountDoesNotConvert(): void {
		$this->fileMapper->method('getByUuid')->willReturn($this->ownedFile());
		$this->signRequestMapper->method('getByIdentifierAndFileId')->willReturnCallback(
			fn (string $key) => $key === 'email' ? $this->signRequest(5) : null
		);
		$this->trust(false); // email matches, but the account is not a trusted OIDC account
		$this->identifyMethodMapper->expects($this->never())->method('update');
		$result = $this->service->reconcile($this->workflow(), $this->claimant('alice', 'req@example.com'));
		$this->assertSame(RequesterReconciliationService::UNRESOLVED_UNVERIFIED, $result);
	}

	public function testTrustedButMismatchedEmailDoesNotConvert(): void {
		$this->fileMapper->method('getByUuid')->willReturn($this->ownedFile());
		$this->signRequestMapper->method('getByIdentifierAndFileId')->willReturnCallback(
			fn (string $key) => $key === 'email' ? $this->signRequest(5) : null
		);
		$this->trust(true); // trusted, but the account email differs from the requester email
		$this->identifyMethodMapper->expects($this->never())->method('update');
		$result = $this->service->reconcile($this->workflow(), $this->claimant('alice', 'different@example.com'));
		$this->assertSame(RequesterReconciliationService::UNRESOLVED_UNVERIFIED, $result);
	}

	public function testTrustedMatchConvertsInPlacePreservingTheRequest(): void {
		$this->fileMapper->method('getByUuid')->willReturn($this->ownedFile());
		$this->signRequestMapper->method('getByIdentifierAndFileId')->willReturnCallback(
			fn (string $key) => $key === 'email' ? $this->signRequest(5) : null
		);
		$this->trust(true); // trusted + case-insensitive email match ('REQ' vs 'req')

		$method = new IdentifyMethod();
		$method->setSignRequestId(5);
		$method->setIdentifierKey('email');
		$method->setIdentifierValue('req@example.com');
		$this->identifyMethodMapper->method('getIdentifyMethodsFromSignRequestId')->willReturn([$method]);

		$this->connection->expects($this->once())->method('beginTransaction');
		$this->connection->expects($this->once())->method('commit');
		$this->connection->expects($this->never())->method('rollBack');
		$this->identifyMethodMapper->expects($this->once())->method('update');

		$result = $this->service->reconcile($this->workflow(), $this->claimant('alice', 'REQ@example.com'));
		$this->assertSame(RequesterReconciliationService::RECONCILED, $result);
		// Same sign_request.id (5) preserved; identity flipped to the account.
		$this->assertSame('account', $method->getIdentifierKey());
		$this->assertSame('alice', $method->getIdentifierValue());
		$this->assertSame(5, $method->getSignRequestId());
	}

	public function testInvitationMarkerConvertsLocalAccountWithoutOidc(): void {
		// Legacy createToSign (local, non-OIDC) account: not trusted by the OIDC
		// policy, but the invitation marker proves the email -> it reconciles.
		$this->fileMapper->method('getByUuid')->willReturn($this->ownedFile());
		$this->signRequestMapper->method('getByIdentifierAndFileId')->willReturnCallback(
			fn (string $key) => $key === 'email' ? $this->signRequest(5) : null
		);
		$this->trust(false); // OIDC policy does NOT vouch
		$this->userConfig->method('getValueString')
			->with('alice', Application::APP_ID, AccountService::TRUSTED_EMAIL_KEY, '')
			->willReturn('req@example.com'); // invitation marker matches

		$method = $this->emailMethod(5, 'req@example.com');
		$this->identifyMethodMapper->method('getIdentifyMethodsFromSignRequestId')->willReturn([$method]);
		$this->connection->expects($this->once())->method('commit');
		$this->identifyMethodMapper->expects($this->once())->method('update');

		$result = $this->service->reconcile($this->workflow(), $this->claimant('alice', 'req@example.com'));
		$this->assertSame(RequesterReconciliationService::RECONCILED, $result);
		$this->assertSame('account', $method->getIdentifierKey());
		$this->assertSame('alice', $method->getIdentifierValue());
	}

	// --- reconcileAuthenticatedSigner (login self-reconcile) ---

	public function testSelfReconcileSkipsUntrustedUser(): void {
		$this->trust(false);
		$this->signRequestMapper->expects($this->never())->method('findUnsignedPublicWorkflowSignRequestIdsByEmail');
		$result = $this->service->reconcileAuthenticatedSigner($this->account('bob', 'bob@example.com'));
		$this->assertSame(0, $result['converted']);
	}

	public function testSelfReconcileConvertsOwnPublicWorkflowRequests(): void {
		$this->trust(true);
		$this->signRequestMapper->method('findUnsignedPublicWorkflowSignRequestIdsByEmail')
			->with('bob@example.com')->willReturn([7]);
		$method = $this->emailMethod(7, 'bob@example.com');
		$this->identifyMethodMapper->method('getIdentifyMethodsFromSignRequestId')->willReturn([$method]);
		$this->connection->expects($this->once())->method('commit');
		$this->identifyMethodMapper->expects($this->once())->method('update');

		$result = $this->service->reconcileAuthenticatedSigner($this->account('bob', 'bob@example.com'));
		$this->assertSame(1, $result['converted']);
		$this->assertSame('account', $method->getIdentifierKey());
		$this->assertSame('bob', $method->getIdentifierValue());
		$this->assertSame(7, $method->getSignRequestId());
	}

	// --- reconcileFileSigners (claim-time mapping of other signers) ---

	public function testClaimSignerReconcileMapsEmailToTrustedAccountInPlace(): void {
		$this->signRequestMapper->method('getByFileId')->with(129)->willReturn([$this->signRequest(7)]);
		$method = $this->emailMethod(7, 'sig@example.com');
		$this->identifyMethodMapper->method('getIdentifyMethodsFromSignRequestId')->willReturn([$method]);
		$this->userManager->method('getByEmail')->with('sig@example.com')
			->willReturn([$this->account('bob', 'sig@example.com')]);
		$this->trust(true);
		$this->identifyMethodMapper->expects($this->once())->method('update');

		$result = $this->service->reconcileFileSigners(129);
		$this->assertSame(1, $result['converted']);
		$this->assertSame('account', $method->getIdentifierKey());
		$this->assertSame('bob', $method->getIdentifierValue());
	}

	public function testClaimSignerReconcileLeavesUnresolvableEmail(): void {
		$this->signRequestMapper->method('getByFileId')->with(129)->willReturn([$this->signRequest(7)]);
		$method = $this->emailMethod(7, 'external@elsewhere.com');
		$this->identifyMethodMapper->method('getIdentifyMethodsFromSignRequestId')->willReturn([$method]);
		$this->userManager->method('getByEmail')->willReturn([]); // no account for that email
		$this->identifyMethodMapper->expects($this->never())->method('update');

		$result = $this->service->reconcileFileSigners(129);
		$this->assertSame(0, $result['converted']);
		$this->assertSame('email', $method->getIdentifierKey());
	}

	// --- uid-as-email fallback (legacy shape: email written as uid, property empty) ---

	/** Legacy account: the email IS the UID and the email PROPERTY is empty. */
	private function legacyAccount(string $emailUid): IUser&MockObject {
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($emailUid);
		$u->method('getEMailAddress')->willReturn(null);
		return $u;
	}

	public function testClaimSignerReconcileMapsUidAsEmailWhenPropertyEmpty(): void {
		$this->signRequestMapper->method('getByFileId')->with(129)->willReturn([$this->signRequest(7)]);
		$method = $this->emailMethod(7, 'bruno@example.com');
		$this->identifyMethodMapper->method('getIdentifyMethodsFromSignRequestId')->willReturn([$method]);
		// No account carries this email PROPERTY (blank Email column) ...
		$this->userManager->method('getByEmail')->willReturn([]);
		// ... but an account's UID *is* this email (legacy provisioning).
		$this->userManager->method('get')->with('bruno@example.com')
			->willReturn($this->legacyAccount('bruno@example.com'));
		$this->trust(true);
		$this->identifyMethodMapper->expects($this->once())->method('update');

		$result = $this->service->reconcileFileSigners(129);
		$this->assertSame(1, $result['converted']);
		$this->assertSame('account', $method->getIdentifierKey());
		$this->assertSame('bruno@example.com', $method->getIdentifierValue());
	}

	public function testClaimSignerUidFallbackRequiresTrust(): void {
		$this->signRequestMapper->method('getByFileId')->with(129)->willReturn([$this->signRequest(7)]);
		$method = $this->emailMethod(7, 'bruno@example.com');
		$this->identifyMethodMapper->method('getIdentifyMethodsFromSignRequestId')->willReturn([$method]);
		$this->userManager->method('getByEmail')->willReturn([]);
		$this->userManager->method('get')->willReturn($this->legacyAccount('bruno@example.com'));
		$this->trust(false); // not OIDC-trusted, and no invitation marker
		$this->identifyMethodMapper->expects($this->never())->method('update');

		$result = $this->service->reconcileFileSigners(129);
		$this->assertSame(0, $result['converted']);
		$this->assertSame('email', $method->getIdentifierKey());
	}

	public function testClaimSignerUidFallbackDoesNotHijackDifferentEmail(): void {
		$this->signRequestMapper->method('getByFileId')->with(129)->willReturn([$this->signRequest(7)]);
		$method = $this->emailMethod(7, 'bruno@example.com');
		$this->identifyMethodMapper->method('getIdentifyMethodsFromSignRequestId')->willReturn([$method]);
		$this->userManager->method('getByEmail')->willReturn([]);
		// UID equals the email, but the email PROPERTY is a DIFFERENT address:
		// attributableEmail returns that other address, so it must NOT convert.
		$this->userManager->method('get')->with('bruno@example.com')
			->willReturn($this->account('bruno@example.com', 'someone-else@example.com'));
		$this->trust(true);
		$this->identifyMethodMapper->expects($this->never())->method('update');

		$result = $this->service->reconcileFileSigners(129);
		$this->assertSame(0, $result['converted']);
		$this->assertSame('email', $method->getIdentifierKey());
	}

	public function testClaimSignerUidFallbackRequiresUidToEqualEmail(): void {
		$this->signRequestMapper->method('getByFileId')->with(129)->willReturn([$this->signRequest(7)]);
		$method = $this->emailMethod(7, 'bruno@example.com');
		$this->identifyMethodMapper->method('getIdentifyMethodsFromSignRequestId')->willReturn([$method]);
		$this->userManager->method('getByEmail')->willReturn([]);
		// Defensive: a backend returning an account whose UID is NOT the email
		// (e.g. a uuid) is rejected by the uid===email guard.
		$this->userManager->method('get')->willReturn($this->legacyAccount('some-uuid-not-an-email'));
		$this->trust(true);
		$this->identifyMethodMapper->expects($this->never())->method('update');

		$result = $this->service->reconcileFileSigners(129);
		$this->assertSame(0, $result['converted']);
		$this->assertSame('email', $method->getIdentifierKey());
	}

	public function testSelfReconcileRecoversLegacyUidAsEmail(): void {
		$this->trust(true);
		$this->signRequestMapper->method('findUnsignedPublicWorkflowSignRequestIdsByEmail')
			->with('leg@example.com')->willReturn([9]);
		$method = $this->emailMethod(9, 'leg@example.com');
		$this->identifyMethodMapper->method('getIdentifyMethodsFromSignRequestId')->willReturn([$method]);
		$this->identifyMethodMapper->expects($this->once())->method('update');

		// Email PROPERTY empty; the uid IS the email -> attributableEmail recovers it.
		$result = $this->service->reconcileAuthenticatedSigner($this->legacyAccount('leg@example.com'));
		$this->assertSame(1, $result['converted']);
		$this->assertSame('account', $method->getIdentifierKey());
		$this->assertSame('leg@example.com', $method->getIdentifierValue());
	}

}
