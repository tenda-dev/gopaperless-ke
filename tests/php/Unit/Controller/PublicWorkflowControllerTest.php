<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\PublicWorkflowController;
use OCA\Libresign\Db\PublicWorkflow;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Helper\ValidateHelper;
use OCA\Libresign\Service\FileService;
use OCA\Libresign\Service\PublicWorkflowService;
use OCA\Libresign\Service\RequesterReconciliationService;
use OCA\Libresign\Service\RequestSignatureService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class PublicWorkflowControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IL10N&MockObject $l10n;
	private IAppConfig&MockObject $appConfig;
	private PublicWorkflowService&MockObject $workflowService;
	private FileService&MockObject $fileService;
	private RequestSignatureService&MockObject $requestSignatureService;
	private ValidateHelper&MockObject $validateHelper;
	private PublicWorkflowController $controller;

	public function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->workflowService = $this->createMock(PublicWorkflowService::class);
		$this->fileService = $this->createMock(FileService::class);
		$this->requestSignatureService = $this->createMock(RequestSignatureService::class);
		$this->validateHelper = $this->createMock(ValidateHelper::class);
		$this->controller = new PublicWorkflowController(
			$this->request,
			$this->l10n,
			$this->createMock(LoggerInterface::class),
			$this->appConfig,
			$this->workflowService,
			$this->fileService,
			$this->requestSignatureService,
			$this->validateHelper,
			$this->createMock(IUserSession::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(RequesterReconciliationService::class),
			$this->createMock(\OCA\Libresign\Service\AccountService::class),
		);
	}

	private function enable(bool $on): void {
		$this->appConfig->method('getValueBool')
			->with('libresign', 'public_workflow_enabled', false)
			->willReturn($on);
	}

	public function testInertWhenDisabled(): void {
		$this->enable(false);
		// Must not even attempt to create a workflow when the flag is off.
		$this->workflowService->expects($this->never())->method('create');

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testResumeRejectsInvalidCapability(): void {
		$this->enable(true);
		$this->workflowService->method('resolveFromToken')
			->willThrowException(new LibresignException('Invalid workflow'));

		$response = $this->controller->resume();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testSecondUploadRejected(): void {
		$this->enable(true);
		$workflow = new PublicWorkflow();
		$workflow->setFileUuid('already-here');
		$this->workflowService->method('resolveFromToken')->willReturn($workflow);
		$this->workflowService->method('hasDocument')->with($workflow)->willReturn(true);
		// One document per workflow: the pipeline must never be invoked again.
		$this->requestSignatureService->expects($this->never())->method('saveFiles');

		$response = $this->controller->uploadDocument();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testSignerPrepRejectedWithoutDocument(): void {
		$this->enable(true);
		$workflow = new PublicWorkflow();
		$this->workflowService->method('resolveFromToken')->willReturn($workflow);
		$this->workflowService->method('hasDocument')->with($workflow)->willReturn(false);
		$this->requestSignatureService->expects($this->never())->method('save');

		$response = $this->controller->saveSigners([['identifyMethods' => [['method' => 'email', 'value' => 'a@b.c']]]]);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testResumeReturnsFormattedDocumentWhenPresent(): void {
		$this->enable(true);
		$workflow = new PublicWorkflow();
		$workflow->setStatus(PublicWorkflow::STATUS_ACTIVE);
		$workflow->setFileUuid('file-uuid');
		$workflow->setExpiresAt(new \DateTime('+72 hours'));
		$this->workflowService->method('resolveFromToken')->willReturn($workflow);
		$this->workflowService->method('hasDocument')->with($workflow)->willReturn(true);
		$this->workflowService->method('resolveOwner')->with($workflow)->willReturn($this->createMock(IUser::class));
		// The owner + uuid come from the resolved row, asserted via the same guard
		// the save path uses, then formatted through the canonical show builder.
		$this->fileService->method('setFileByUuid')->with('file-uuid')->willReturnSelf();
		$this->fileService->method('setMe')->willReturnSelf();
		$this->fileService->method('showSigners')->willReturnSelf();
		$this->fileService->method('showVisibleElements')->willReturnSelf();
		$this->fileService->method('toArray')->willReturn([
			'uuid' => 'file-uuid',
			'signers' => [['signRequestId' => 1, 'displayName' => 'Asha', 'email' => 'asha@example.co.ke']],
			'visibleElements' => [],
		]);

		$response = $this->controller->resume();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($data['hasDocument']);
		$this->assertIsArray($data['document']);
		$this->assertSame('file-uuid', $data['document']['uuid']);
		$this->assertSame('asha@example.co.ke', $data['document']['signers'][0]['email']);
	}

	public function testResumeReturnsNullDocumentWhenFormattingFails(): void {
		$this->enable(true);
		$workflow = new PublicWorkflow();
		$workflow->setStatus(PublicWorkflow::STATUS_ACTIVE);
		$workflow->setFileUuid('file-uuid');
		$workflow->setExpiresAt(new \DateTime('+72 hours'));
		$this->workflowService->method('resolveFromToken')->willReturn($workflow);
		$this->workflowService->method('hasDocument')->with($workflow)->willReturn(true);
		$this->workflowService->method('resolveOwner')->willReturn($this->createMock(IUser::class));
		// A formatting failure must degrade to a null document, never a hard error
		// and never a leaked internal message to the anonymous caller.
		$this->fileService->method('setFileByUuid')->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller->resume();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($data['hasDocument']);
		$this->assertNull($data['document']);
	}

	public function testResumeOmitsDocumentWhenNoDocument(): void {
		$this->enable(true);
		$workflow = new PublicWorkflow();
		$workflow->setStatus(PublicWorkflow::STATUS_ACTIVE);
		$workflow->setExpiresAt(new \DateTime('+72 hours'));
		$this->workflowService->method('resolveFromToken')->willReturn($workflow);
		$this->workflowService->method('hasDocument')->with($workflow)->willReturn(false);
		// No document → the formatter is never touched.
		$this->fileService->expects($this->never())->method('setFileByUuid');

		$response = $this->controller->resume();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($data['hasDocument']);
		$this->assertNull($data['document']);
	}
	public function testDeleteVisibleElementInertWhenDisabled(): void {
		$this->enable(false);
		$this->workflowService->expects($this->never())->method('deleteVisibleElement');
		$response = $this->controller->deleteVisibleElement(5);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testDeleteVisibleElementRejectsInvalidCapability(): void {
		$this->enable(true);
		$this->workflowService->method('resolveFromToken')->willThrowException(new LibresignException('Invalid workflow'));
		$this->workflowService->expects($this->never())->method('deleteVisibleElement');
		$response = $this->controller->deleteVisibleElement(5);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testDeleteVisibleElementSurfacesValidationError(): void {
		$this->enable(true);
		$workflow = new PublicWorkflow();
		$this->workflowService->method('resolveFromToken')->willReturn($workflow);
		$this->workflowService->method('deleteVisibleElement')
			->willThrowException(new LibresignException('Signature position not found'));
		$response = $this->controller->deleteVisibleElement(5);
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('Signature position not found', $response->getData()['message']);
	}

	public function testDeleteVisibleElementReturnsRefreshedDocument(): void {
		$this->enable(true);
		$workflow = new PublicWorkflow();
		$workflow->setFileUuid('file-uuid');
		$this->workflowService->method('resolveFromToken')->willReturn($workflow);
		$this->workflowService->expects($this->once())->method('deleteVisibleElement')->with($workflow, 7);
		// Refreshed-document path (same formatter resume uses).
		$this->workflowService->method('resolveOwner')->willReturn($this->createMock(IUser::class));
		$this->fileService->method('setFileByUuid')->willReturnSelf();
		$this->fileService->method('setMe')->willReturnSelf();
		$this->fileService->method('showSigners')->willReturnSelf();
		$this->fileService->method('showVisibleElements')->willReturnSelf();
		$this->fileService->method('toArray')->willReturn(['uuid' => 'file-uuid', 'visibleElements' => []]);
		$response = $this->controller->deleteVisibleElement(7);
		$data = $response->getData();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertIsArray($data['document']);
		$this->assertSame('file-uuid', $data['document']['uuid']);
	}

}
