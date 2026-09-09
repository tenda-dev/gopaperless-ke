<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Db\File as FileEntity;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\SignRequest as SignRequestEntity;
use OCA\Libresign\Service\FileAccessService;
use OCA\Libresign\Service\SignFileService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class FileAccessServiceTest extends TestCase {
	private FileMapper&MockObject $fileMapper;
	private SignFileService&MockObject $signFileService;
	private IUserSession&MockObject $userSession;
	private FileAccessService $service;

	protected function setUp(): void {
		$this->fileMapper = $this->createMock(FileMapper::class);
		$this->signFileService = $this->createMock(SignFileService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->service = new FileAccessService(
			$this->fileMapper,
			$this->signFileService,
			$this->userSession,
		);
	}

	#[DataProvider('provideFileAccessScenarios')]
	public function testUserCanAccessFileUsesOwnershipAndSignerRules(
		string $method,
		string $mapperMethod,
		int $identifier,
		string $ownerId,
		string $userId,
		bool $canSign,
		bool $expected,
	): void {
		$user = $this->mockUser($userId);
		$file = $this->createFileEntity(ownerId: $ownerId);

		$this->fileMapper->expects($this->once())
			->method($mapperMethod)
			->with($identifier)
			->willReturn($file);

		$expectsSignerLookup = $ownerId !== $userId;
		if (!$expectsSignerLookup) {
			$this->signFileService->expects($this->never())->method('getSignRequestToSign');
		} elseif ($canSign) {
			$this->signFileService->expects($this->once())
				->method('getSignRequestToSign')
				->with($file, null, $user);
		} else {
			$this->signFileService->expects($this->once())
				->method('getSignRequestToSign')
				->with($file, null, $user)
				->willThrowException(new \RuntimeException('not a signer'));
		}

		$this->assertSame($expected, $this->service->{$method}($identifier, $user));
	}

	public function testUserCanViewFileByIdReturnsTrueForOwner(): void {
		$user = $this->mockUser('owner');
		$file = $this->createFileEntity(ownerId: 'owner');

		$this->fileMapper->expects($this->once())
			->method('getById')
			->with(10)
			->willReturn($file);
		$this->signFileService->expects($this->never())->method('getSignRequestToSign');
		$this->signFileService->expects($this->never())->method('findExistingSignRequestForUser');

		$this->assertTrue($this->service->userCanViewFileById(10, $user));
	}

	public function testUserCanViewFileByIdReturnsTrueForExistingInternalSigner(): void {
		$user = $this->mockUser('signer-1');
		$file = $this->createFileEntity(ownerId: 'owner');
		$signRequest = $this->createMock(SignRequestEntity::class);

		$this->fileMapper->method('getById')->with(20)->willReturn($file);
		$this->signFileService->expects($this->never())->method('getSignRequestToSign');
		$this->signFileService->expects($this->once())
			->method('findExistingSignRequestForUser')
			->with($file, $user)
			->willReturn($signRequest);

		$this->assertTrue($this->service->userCanViewFileById(20, $user));
	}

	public function testUserCanViewFileByIdReturnsTrueForMatchingEmailSigner(): void {
		// From FileAccessService's perspective a matching email signer is
		// indistinguishable from any other resolved sign request: the
		// email-vs-account matching itself lives in
		// SignFileService::findExistingSignRequestForUser(), which this
		// mock stands in for.
		$user = $this->mockUser('user-1');
		$file = $this->createFileEntity(ownerId: 'owner');
		$signRequest = $this->createMock(SignRequestEntity::class);

		$this->fileMapper->method('getById')->with(21)->willReturn($file);
		$this->signFileService->expects($this->once())
			->method('findExistingSignRequestForUser')
			->with($file, $user)
			->willReturn($signRequest);

		$this->assertTrue($this->service->userCanViewFileById(21, $user));
	}

	public function testUserCanViewFileByIdReturnsFalseForUnrelatedAuthenticatedUser(): void {
		$user = $this->mockUser('outsider');
		$file = $this->createFileEntity(ownerId: 'owner');

		$this->fileMapper->method('getById')->with(30)->willReturn($file);
		$this->signFileService->expects($this->never())->method('getSignRequestToSign');
		$this->signFileService->expects($this->once())
			->method('findExistingSignRequestForUser')
			->with($file, $user)
			->willReturn(null);

		$this->assertFalse($this->service->userCanViewFileById(30, $user));
	}

	public function testUserCanViewFileByIdReturnsFalseForUnauthenticatedUser(): void {
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);
		$this->fileMapper->expects($this->never())->method('getById');

		$this->assertFalse($this->service->userCanViewFileById(40));
	}

	public function testUserCanViewFileByIdReturnsTrueForFullySignedFileWithExistingSigner(): void {
		// findExistingSignRequestForUser() is the read-only lookup that
		// works regardless of file status; this asserts userCanViewFileById()
		// relies on it (and not on getSignRequestToSign(), which would throw
		// for an already-signed file).
		$user = $this->mockUser('signer-1');
		$file = $this->createFileEntity(ownerId: 'owner');
		$signRequest = $this->createMock(SignRequestEntity::class);

		$this->fileMapper->method('getById')->with(50)->willReturn($file);
		$this->signFileService->expects($this->never())->method('getSignRequestToSign');
		$this->signFileService->expects($this->once())
			->method('findExistingSignRequestForUser')
			->with($file, $user)
			->willReturn($signRequest);

		$this->assertTrue($this->service->userCanViewFileById(50, $user));
	}

	public function testUserCanViewFileByIdReturnsFalseForMissingFile(): void {
		$user = $this->mockUser('someone');

		$this->fileMapper->expects($this->once())
			->method('getById')
			->with(60)
			->willThrowException(new DoesNotExistException('not found'));
		$this->signFileService->expects($this->never())->method('findExistingSignRequestForUser');

		$this->assertFalse($this->service->userCanViewFileById(60, $user));
	}

	public function testUserCanViewFileByIdReturnsFalseWhenAuthorizationLookupThrows(): void {
		$user = $this->mockUser('someone');
		$file = $this->createFileEntity(ownerId: 'owner');

		$this->fileMapper->method('getById')->with(70)->willReturn($file);
		$this->signFileService->expects($this->once())
			->method('findExistingSignRequestForUser')
			->with($file, $user)
			->willThrowException(new \RuntimeException('lookup failed'));

		$this->assertFalse($this->service->userCanViewFileById(70, $user));
	}

	#[DataProvider('provideMissingUserScenarios')]
	public function testUserCanAccessFileReturnsFalseWithoutResolvedUser(
		string $method,
		string $mapperMethod,
		int $identifier,
	): void {
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);
		$this->fileMapper->expects($this->never())->method($mapperMethod);

		$this->assertFalse($this->service->{$method}($identifier));
	}

	public static function provideFileAccessScenarios(): array {
		return [
			'owner by file id' => ['userCanAccessFileById', 'getById', 10, 'owner', 'owner', false, true],
			'signer by file id' => ['userCanAccessFileById', 'getById', 20, 'owner', 'signer', true, true],
			'outsider by file id' => ['userCanAccessFileById', 'getById', 30, 'owner', 'outsider', false, false],
			'signer by node id' => ['userCanAccessFileByNodeId', 'getByNodeId', 99, 'owner', 'signer', true, true],
			'outsider by node id' => ['userCanAccessFileByNodeId', 'getByNodeId', 100, 'owner', 'outsider', false, false],
		];
	}

	public static function provideMissingUserScenarios(): array {
		return [
			'file id without user' => ['userCanAccessFileById', 'getById', 40],
			'node id without user' => ['userCanAccessFileByNodeId', 'getByNodeId', 41],
		];
	}

	private function mockUser(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function createFileEntity(string $ownerId): FileEntity {
		$file = new FileEntity();
		$file->setUserId($ownerId);
		return $file;
	}
}
