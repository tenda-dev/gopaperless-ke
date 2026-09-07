<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Controller\PageController;
use OCA\Libresign\Db\File as FileEntity;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\SignRequest as SignRequestEntity;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Helper\ValidateHelper;
use OCA\Libresign\Service\AccountService;
use OCA\Libresign\Service\DocMdp\ConfigService;
use OCA\Libresign\Service\File\FileListService;
use OCA\Libresign\Service\FileAccessService;
use OCA\Libresign\Service\FileService;
use OCA\Libresign\Service\IdentifyMethodService;
use OCA\Libresign\Service\RequestSignatureService;
use OCA\Libresign\Service\SessionService;
use OCA\Libresign\Service\SignerElementsService;
use OCA\Libresign\Service\SignFileService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IInitialStateService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class PageControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private AccountService&MockObject $accountService;
	private FileService&MockObject $fileService;
	private SignFileService&MockObject $signFileService;
	private SignerElementsService&MockObject $signerElementsService;
	private IUser&MockObject $currentUser;
	private FileMapper&MockObject $fileMapper;
	private FileAccessService&MockObject $fileAccessService;
	private PageController $controller;

	public function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getServerHost')->willReturn('localhost:8080');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->currentUser = $this->createMock(IUser::class);
		$this->currentUser->method('getUID')->willReturn('requester');
		$this->setUserLoggedIn(true);

		$this->accountService = $this->createMock(AccountService::class);
		$this->accountService->method('getConfig')->willReturn([]);
		$this->accountService->method('getConfigFilters')->willReturn([]);
		$this->accountService->method('getConfigSorting')->willReturn([]);
		$this->accountService->method('getCertificateEngineName')->willReturn('openssl');

		$this->fileService = $this->createMock(FileService::class);
		$this->fileService->method('setFile')->willReturnSelf();
		$this->fileService->method('setSignRequest')->willReturnSelf();
		$this->fileService->method('setHost')->willReturnSelf();
		$this->fileService->method('setMe')->willReturnSelf();
		$this->fileService->method('setSignerIdentified')->willReturnSelf();
		$this->fileService->method('setIdentifyMethodId')->willReturnSelf();
		$this->fileService->method('showVisibleElements')->willReturnSelf();
		$this->fileService->method('showSigners')->willReturnSelf();
		$this->fileService->method('showSettings')->willReturnSelf();
		$this->fileService->method('toArray')->willReturn([
			'id' => 5,
			'nodeId' => 50,
			'status' => 1,
			'statusText' => 'Ready to sign',
			'signers' => [],
			'visibleElements' => [],
			'settings' => [
				'needIdentificationDocuments' => false,
				'identificationDocumentsWaitingApproval' => false,
			],
		]);

		$this->signFileService = $this->createMock(SignFileService::class);
		$this->signFileService->method('getPdfUrlsForSigning')->willReturn(['/apps/libresign/pdf/sign-uuid']);

		$this->signerElementsService = $this->createMock(SignerElementsService::class);
		$this->signerElementsService->method('getElementsFromSessionAsArray')->willReturn([]);
		$this->signerElementsService->method('getUserElements')->willReturn([]);

		$this->fileMapper = $this->createMock(FileMapper::class);
		$this->fileAccessService = $this->createMock(FileAccessService::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new PageController(
			request: $this->request,
			userSession: $this->userSession,
			sessionService: $this->createMock(SessionService::class),
			initialState: new \OC\AppFramework\Services\InitialState(
				$this->createMock(IInitialStateService::class),
				Application::APP_ID,
			),
			accountService: $this->accountService,
			signFileService: $this->signFileService,
			requestSignatureService: \OCP\Server::get(RequestSignatureService::class),
			signerElementsService: $this->signerElementsService,
			l10n: $l10n,
			identifyMethodService: $this->createConfiguredMock(IdentifyMethodService::class, [
				'getIdentifyMethodsSettings' => [],
			]),
			appConfig: \OCP\Server::get(IAppConfig::class),
			fileService: $this->fileService,
			fileListService: \OCP\Server::get(FileListService::class),
			fileMapper: $this->fileMapper,
			signRequestMapper: \OCP\Server::get(\OCA\Libresign\Db\SignRequestMapper::class),
			fileAccessService: $this->fileAccessService,
			logger: \OCP\Server::get(LoggerInterface::class),
			validateHelper: $this->createMock(ValidateHelper::class),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			urlGenerator: \OCP\Server::get(IURLGenerator::class),
			docMdpConfigService: $this->createConfiguredMock(ConfigService::class, [
				'getConfig' => [],
			]),
			appManager: \OCP\Server::get(IAppManager::class),
		);
	}

	private function setUserLoggedIn(bool $loggedIn): void {
		$this->userSession->method('isLoggedIn')->willReturn($loggedIn);
		$this->userSession->method('getUser')->willReturn($loggedIn ? $this->currentUser : null);
	}

	public function testIndexAllowsSelfWorkerSrcDomain(): void {
		$response = $this->controller->index();

		self::assertStringContainsString("worker-src 'self'", $response->getContentSecurityPolicy()->buildPolicy());
	}

	public function testIndexRedirectsAnonymousToLoginWhenLandingDisabled(): void {
		$this->setUserLoggedIn(false);
		self::getMockAppConfig()->setValueBool(Application::APP_ID, 'public_upload_landing_enabled', false);

		$response = $this->controller->index();

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertStringContainsString('login', $response->getRedirectURL());
	}

	public function testIndexRedirectsAnonymousToPublicUploadWhenLandingEnabled(): void {
		$this->setUserLoggedIn(false);
		self::getMockAppConfig()->setValueBool(Application::APP_ID, 'public_upload_landing_enabled', true);

		$response = $this->controller->index();

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertStringContainsString('/p/upload', $response->getRedirectURL());
	}

	public function testPublicUploadRedirectsAnonymousToLoginWhenLandingDisabled(): void {
		$this->setUserLoggedIn(false);
		self::getMockAppConfig()->setValueBool(Application::APP_ID, 'public_upload_landing_enabled', false);

		$response = $this->controller->publicUpload();

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertStringContainsString('login', $response->getRedirectURL());
	}

	/**
	 * Builds a PageController with fully controllable IAppManager, IURLGenerator
	 * and IInitialStateService, so the public_upload_oidc_login_url tests below
	 * don't depend on whether `user_oidc` happens to be installed/enabled in
	 * whatever Nextcloud instance these tests run against.
	 *
	 * @param array<string, mixed> $providedInitialState Captured by reference: filled with every provideInitialState() key => value pushed during the call.
	 */
	private function buildControllerWithMocks(
		IAppManager&MockObject $appManager,
		IURLGenerator&MockObject $urlGenerator,
		array &$providedInitialState,
	): PageController {
		$initialStateService = $this->createMock(IInitialStateService::class);
		$initialStateService->method('provideInitialState')
			->willReturnCallback(function (string $appName, string $key, mixed $data) use (&$providedInitialState): void {
				$providedInitialState[$key] = $data;
			});

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PageController(
			request: $this->request,
			userSession: $this->userSession,
			sessionService: $this->createMock(SessionService::class),
			initialState: new \OC\AppFramework\Services\InitialState($initialStateService, Application::APP_ID),
			accountService: $this->accountService,
			signFileService: $this->signFileService,
			requestSignatureService: \OCP\Server::get(RequestSignatureService::class),
			signerElementsService: $this->signerElementsService,
			l10n: $l10n,
			identifyMethodService: $this->createConfiguredMock(IdentifyMethodService::class, [
				'getIdentifyMethodsSettings' => [],
			]),
			appConfig: \OCP\Server::get(IAppConfig::class),
			fileService: $this->fileService,
			fileListService: \OCP\Server::get(FileListService::class),
			fileMapper: $this->fileMapper,
			signRequestMapper: \OCP\Server::get(SignRequestMapper::class),
			fileAccessService: $this->fileAccessService,
			logger: \OCP\Server::get(LoggerInterface::class),
			validateHelper: $this->createMock(ValidateHelper::class),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			urlGenerator: $urlGenerator,
			docMdpConfigService: $this->createConfiguredMock(ConfigService::class, [
				'getConfig' => [],
			]),
			appManager: $appManager,
		);
	}

	public function testPublicUploadOidcLoginUrlIsEmptyWhenProviderUnconfigured(): void {
		$this->setUserLoggedIn(false);
		self::getMockAppConfig()->setValueBool(Application::APP_ID, 'public_upload_landing_enabled', true);
		self::getMockAppConfig()->setValueInt(Application::APP_ID, 'public_upload_login_provider_id', 0);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects(self::never())->method('isEnabledForUser');
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects(self::never())->method('linkToRoute')->with('user_oidc.login.login');

		$provided = [];
		$controller = $this->buildControllerWithMocks($appManager, $urlGenerator, $provided);

		$controller->publicUpload();

		self::assertArrayHasKey('public_upload_oidc_login_url', $provided);
		self::assertSame('', $provided['public_upload_oidc_login_url']);
	}

	public function testPublicUploadOidcLoginUrlIsEmptyWhenUserOidcIsNotEnabled(): void {
		$this->setUserLoggedIn(false);
		self::getMockAppConfig()->setValueBool(Application::APP_ID, 'public_upload_landing_enabled', true);
		self::getMockAppConfig()->setValueInt(Application::APP_ID, 'public_upload_login_provider_id', 2);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->with('user_oidc')->willReturn(false);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects(self::never())->method('linkToRoute')->with('user_oidc.login.login');

		$provided = [];
		$controller = $this->buildControllerWithMocks($appManager, $urlGenerator, $provided);

		$controller->publicUpload();

		self::assertArrayHasKey('public_upload_oidc_login_url', $provided);
		self::assertSame('', $provided['public_upload_oidc_login_url']);
	}

	public function testPublicUploadExposesOidcLoginUrlWhenProviderConfiguredAndUserOidcEnabled(): void {
		$this->setUserLoggedIn(false);
		self::getMockAppConfig()->setValueBool(Application::APP_ID, 'public_upload_landing_enabled', true);
		self::getMockAppConfig()->setValueInt(Application::APP_ID, 'public_upload_login_provider_id', 2);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->with('user_oidc')->willReturn(true);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')
			->willReturnCallback(function (string $route, array $params = []): string {
				if ($route === 'libresign.page.indexFPath') {
					self::assertSame(['path' => 'request'], $params);
					return '/apps/libresign/f/request';
				}
				self::assertSame('user_oidc.login.login', $route);
				self::assertSame([
					'providerId' => 2,
					'redirectUrl' => '/apps/libresign/f/request',
				], $params);
				return '/apps/user_oidc/login/2?redirectUrl=%2Fapps%2Flibresign%2Ff%2Frequest';
			});

		$provided = [];
		$controller = $this->buildControllerWithMocks($appManager, $urlGenerator, $provided);

		$controller->publicUpload();

		self::assertArrayHasKey('public_upload_oidc_login_url', $provided);
		self::assertSame(
			'/apps/user_oidc/login/2?redirectUrl=%2Fapps%2Flibresign%2Ff%2Frequest',
			$provided['public_upload_oidc_login_url'],
		);
	}

	public function testPublicSignAllowsSelfWorkerSrcDomain(): void {
		$fileEntity = new FileEntity();
		$fileEntity->setId(5);
		$fileEntity->setName('small_valid');
		$fileEntity->setNodeId(50);
		$fileEntity->setNodeType('file');

		$signRequestEntity = new SignRequestEntity();
		$signRequestEntity->setFileId(5);
		$signRequestEntity->setUuid('sign-uuid');
		$signRequestEntity->setDescription('');
		$this->signFileService->method('getSignRequestByUuid')->willReturn($signRequestEntity);
		$this->signFileService->method('getFile')->willReturn($fileEntity);
		$this->controller->loadNextcloudFileFromSignRequestUuid('sign-uuid');

		$response = $this->controller->sign('sign-uuid');

		self::assertStringContainsString("worker-src 'self'", $response->getContentSecurityPolicy()->buildPolicy());
	}

	public function testGetPdfReturnsFileWhenRestrictionSettingDisabled(): void {
		self::getMockAppConfig()->setValueBool(Application::APP_ID, 'restrict_validation_document_access', false);

		$node = $this->createMock(\OCP\Files\File::class);
		$node->method('getMimeType')->willReturn('application/pdf');
		$this->accountService->method('getPdfByUuid')->with('file-uuid')->willReturn($node);

		$this->fileMapper->expects($this->never())->method('getByUuid');
		$this->fileAccessService->expects($this->never())->method('userCanViewFileById');

		$response = $this->controller->getPdf('file-uuid');

		self::assertInstanceOf(FileDisplayResponse::class, $response);
	}

	public function testGetPdfAllowsOwnerWhenRestrictionSettingEnabled(): void {
		self::getMockAppConfig()->setValueBool(Application::APP_ID, 'restrict_validation_document_access', true);

		$libresignFile = new FileEntity();
		$libresignFile->setId(5);
		$this->fileMapper->method('getByUuid')->with('file-uuid')->willReturn($libresignFile);

		$this->fileAccessService->method('userCanViewFileById')->with(5)->willReturn(true);

		$node = $this->createMock(\OCP\Files\File::class);
		$node->method('getMimeType')->willReturn('application/pdf');
		$this->accountService->method('getPdfByUuid')->with('file-uuid')->willReturn($node);

		$response = $this->controller->getPdf('file-uuid');

		self::assertInstanceOf(FileDisplayResponse::class, $response);
	}

	public function testGetPdfDeniesUnrelatedAuthenticatedUserWhenRestrictionSettingEnabled(): void {
		self::getMockAppConfig()->setValueBool(Application::APP_ID, 'restrict_validation_document_access', true);

		$libresignFile = new FileEntity();
		$libresignFile->setId(5);
		$this->fileMapper->method('getByUuid')->with('file-uuid')->willReturn($libresignFile);

		$this->fileAccessService->method('userCanViewFileById')->with(5)->willReturn(false);

		// getPdf() must reach and enforce the denial via the existing
		// LibresignException flow (caught by InjectionMiddleware::afterException()
		// and rendered as the existing unauthorized/error page) rather than
		// returning a bare DataResponse, so the PDF is never served either way.
		$this->accountService->expects($this->never())->method('getPdfByUuid');

		try {
			$this->controller->getPdf('file-uuid');
			self::fail('Expected a LibresignException to be thrown for an unauthorized request.');
		} catch (LibresignException $e) {
			self::assertSame(Http::STATUS_FORBIDDEN, $e->getCode());
			$payload = json_decode($e->getMessage(), true);
			self::assertSame('Access denied', $payload['title']);
			self::assertSame('You do not have permission to view this document', $payload['errors'][0]['message']);
		}
	}

	public function testGetPdfDeniesUnauthenticatedVisitorWhenRestrictionSettingEnabled(): void {
		self::getMockAppConfig()->setValueBool(Application::APP_ID, 'restrict_validation_document_access', true);
		$this->setUserLoggedIn(false);

		$libresignFile = new FileEntity();
		$libresignFile->setId(5);
		$this->fileMapper->method('getByUuid')->with('file-uuid')->willReturn($libresignFile);

		// FileAccessService resolves the current user from IUserSession itself when
		// no user is passed; with nobody logged in it returns false. The controller
		// does not pass a user explicitly, so this simulates that path via the mock.
		$this->fileAccessService->method('userCanViewFileById')->with(5)->willReturn(false);

		$this->accountService->expects($this->never())->method('getPdfByUuid');

		try {
			$this->controller->getPdf('file-uuid');
			self::fail('Expected a LibresignException to be thrown for an unauthorized request.');
		} catch (LibresignException $e) {
			self::assertSame(Http::STATUS_FORBIDDEN, $e->getCode());
			$payload = json_decode($e->getMessage(), true);
			self::assertSame('Access denied', $payload['title']);
			self::assertSame('You do not have permission to view this document', $payload['errors'][0]['message']);
		}
	}

	public function testGetPdfReturnsNotFoundForUnknownUuidWhenRestrictionSettingEnabled(): void {
		self::getMockAppConfig()->setValueBool(Application::APP_ID, 'restrict_validation_document_access', true);

		$this->fileMapper->method('getByUuid')->with('missing-uuid')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

		$this->fileAccessService->expects($this->never())->method('userCanViewFileById');
		$this->accountService->expects($this->never())->method('getPdfByUuid');

		$response = $this->controller->getPdf('missing-uuid');

		self::assertInstanceOf(DataResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testCanViewValidationDocumentReturnsTrueWhenRestrictionSettingDisabled(): void {
		self::getMockAppConfig()->setValueBool(Application::APP_ID, 'restrict_validation_document_access', false);

		$this->fileAccessService->expects($this->never())->method('userCanViewFileById');

		$result = self::invokePrivate($this->controller, 'canViewValidationDocument', [5]);

		self::assertTrue($result);
	}

	public function testCanViewValidationDocumentUsesUserCanViewFileByIdWhenRestrictionSettingEnabled(): void {
		self::getMockAppConfig()->setValueBool(Application::APP_ID, 'restrict_validation_document_access', true);

		$this->fileAccessService->expects($this->once())
			->method('userCanViewFileById')
			->with(5)
			->willReturn(true);

		$result = self::invokePrivate($this->controller, 'canViewValidationDocument', [5]);

		self::assertTrue($result);
	}

	public function testCanViewValidationDocumentDeniesUnauthorizedUserWhenRestrictionSettingEnabled(): void {
		self::getMockAppConfig()->setValueBool(Application::APP_ID, 'restrict_validation_document_access', true);

		$this->fileAccessService->expects($this->once())
			->method('userCanViewFileById')
			->with(5)
			->willReturn(false);

		$result = self::invokePrivate($this->controller, 'canViewValidationDocument', [5]);

		self::assertFalse($result);
	}
}
