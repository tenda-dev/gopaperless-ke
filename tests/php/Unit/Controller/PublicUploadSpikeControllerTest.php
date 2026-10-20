<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Controller\PublicUploadSpikeController;
use OCA\Libresign\Db\File as FileEntity;
use OCA\Libresign\Enum\FileStatus;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\AccountService;
use OCA\Libresign\Service\FileService;
use OCA\Libresign\Service\RequestSignatureService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit coverage for the public-upload temporary-owner technical spike.
 *
 * Asserts the invariants the spike exists to prove, at the controller wiring
 * level: the temporary owner is created server-side and passed explicitly
 * through the save pipeline, the result is a DRAFT owned by that user, the
 * endpoint is inert unless the flag is on, and a failed upload cleans its
 * temporary user up. Physical storage resolution is covered by the audit
 * (FolderService) and by the manual staging run, not here.
 */
final class PublicUploadSpikeControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IL10N&MockObject $l10n;
	private LoggerInterface&MockObject $logger;
	private IAppConfig&MockObject $appConfig;
	private AccountService&MockObject $accountService;
	private FileService&MockObject $fileService;
	private RequestSignatureService&MockObject $requestSignatureService;
	private PublicUploadSpikeController $controller;

	public function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->fileService = $this->createMock(FileService::class);
		$this->requestSignatureService = $this->createMock(RequestSignatureService::class);

		$this->controller = new PublicUploadSpikeController(
			$this->request,
			$this->l10n,
			$this->logger,
			$this->appConfig,
			$this->accountService,
			$this->fileService,
			$this->requestSignatureService,
		);
	}

	private function enableSpike(bool $enabled): void {
		$this->appConfig->method('getValueBool')
			->with(Application::APP_ID, 'public_upload_spike_enabled', false)
			->willReturn($enabled);
	}

	private function fakeUpload(): array {
		return [
			'name' => 'contract.pdf',
			'tmp_name' => '/tmp/php_spike_upload',
			'type' => 'application/pdf',
			'size' => 1024,
			'error' => 0,
		];
	}

	public function testDisabledEndpointIsInertAndCreatesNoUser(): void {
		$this->enableSpike(false);
		// The inert guard must short-circuit before any user is created.
		$this->accountService->expects($this->never())->method('createTemporaryUser');
		$this->request->expects($this->never())->method('getUploadedFile');

		$response = $this->controller->upload();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testMissingFileReturnsUnprocessable(): void {
		$this->enableSpike(true);
		$this->request->method('getUploadedFile')->with('file')->willReturn(null);
		$this->accountService->expects($this->never())->method('createTemporaryUser');

		$response = $this->controller->upload();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testMultiFileShapeIsRejected(): void {
		$this->enableSpike(true);
		$this->request->method('getUploadedFile')->with('file')->willReturn([
			'name' => ['a.pdf', 'b.pdf'],
			'tmp_name' => ['/tmp/a', '/tmp/b'],
			'type' => ['application/pdf', 'application/pdf'],
			'size' => [1, 2],
			'error' => [0, 0],
		]);
		$this->accountService->expects($this->never())->method('createTemporaryUser');

		$response = $this->controller->upload();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testHappyPathCreatesDraftOwnedByTemporaryUser(): void {
		$this->enableSpike(true);
		$upload = $this->fakeUpload();
		$this->request->method('getUploadedFile')->with('file')->willReturn($upload);

		$tempUid = 'gopaperless_public_11111111-2222-3333-4444-555555555555';
		$tempUser = $this->createMock(IUser::class);
		$tempUser->method('getUID')->willReturn($tempUid);

		$this->accountService->expects($this->once())
			->method('createTemporaryUser')->willReturn($tempUser);
		$this->accountService->expects($this->once())
			->method('isTemporaryUser')->with($tempUser)->willReturn(true);
		$this->accountService->expects($this->never())->method('deleteTemporaryUser');
		$this->fileService->expects($this->once())
			->method('validateUploadedFile')->with($upload);

		$file = new FileEntity();
		$file->setUserId($tempUid);
		$file->setStatus(FileStatus::DRAFT->value);
		$file->setUuid('spike-uuid-abc');
		$file->setNodeId(4242);

		// The temporary user must be the explicit owner passed through the
		// existing save pipeline, carrying the uploaded file unchanged.
		$this->requestSignatureService->expects($this->once())
			->method('saveFiles')
			->with($this->callback(function (array $data) use ($tempUser, $upload): bool {
				return $data['userManager'] === $tempUser
					&& $data['files'][0]['uploadedFile'] === $upload
					&& ($data['settings'] ?? null) === [];
			}))
			->willReturn(['file' => $file, 'children' => [$file]]);

		$response = $this->controller->upload();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($tempUid, $data['ownerUserId']);
		$this->assertSame($tempUid, $data['expectedOwnerUserId']);
		$this->assertTrue($data['ownerMatches']);
		$this->assertSame(FileStatus::DRAFT->value, $data['status']);
		$this->assertTrue($data['isDraft']);
		$this->assertSame('spike-uuid-abc', $data['fileUuid']);
		$this->assertSame(4242, $data['nodeId']);
	}

	public function testFailedUploadCleansUpTemporaryUser(): void {
		$this->enableSpike(true);
		$this->request->method('getUploadedFile')->with('file')->willReturn($this->fakeUpload());

		$tempUser = $this->createMock(IUser::class);
		$tempUser->method('getUID')->willReturn('gopaperless_public_dead-beef');

		$this->accountService->method('createTemporaryUser')->willReturn($tempUser);
		$this->accountService->method('isTemporaryUser')->with($tempUser)->willReturn(true);
		$this->requestSignatureService->method('saveFiles')
			->willThrowException(new LibresignException('boom'));

		// Cleanup-on-failure: the orphan temporary user must be deleted.
		$this->accountService->expects($this->once())
			->method('deleteTemporaryUser')->with($tempUser);

		$response = $this->controller->upload();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}
}
