<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Enum\FileStatus;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\AccountService;
use OCA\Libresign\Service\FileService;
use OCA\Libresign\Service\RequestSignatureService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * TECHNICAL SPIKE — NOT PRODUCTION.
 *
 * Proves an unauthenticated browser can create a normal DRAFT through the
 * existing upload/save machinery, owned by a server-created temporary user,
 * without the browser ever authenticating as that user.
 *
 * Safety: the temporary IUser is created and resolved entirely server-side (the
 * browser never supplies a UID or receives credentials) and the endpoint operates
 * on that exact object for the whole request. It does not go through the
 * group-based ValidateHelper::canRequestSign role, and is inert unless
 * public_upload_spike_enabled is on. Node creation, ownership and DRAFT
 * persistence are delegated to RequestSignatureService::saveFiles() with the
 * temporary user passed explicitly — no business logic is duplicated here.
 */
class PublicUploadSpikeController extends Controller {
	public function __construct(
		IRequest $request,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
		private AccountService $accountService,
		private FileService $fileService,
		private RequestSignatureService $requestSignatureService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Spike: upload a single document as a server-created temporary user.
	 *
	 * Reuses the existing upload/save pipeline. Returns only the minimal
	 * information needed to verify the ownership and DRAFT invariants — never
	 * credentials or privileged tokens.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 5, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/p/upload-spike/submit')]
	public function upload(): JSONResponse {
		if (!$this->appConfig->getValueBool(Application::APP_ID, 'public_upload_spike_enabled', false)) {
			return new JSONResponse(
				['message' => $this->l10n->t('Public upload spike is disabled')],
				Http::STATUS_NOT_FOUND,
			);
		}

		$uploadedFile = $this->request->getUploadedFile('file');
		if (!$uploadedFile || !isset($uploadedFile['tmp_name'])) {
			return new JSONResponse(
				['message' => $this->l10n->t('No file uploaded')],
				Http::STATUS_UNPROCESSABLE_ENTITY,
			);
		}
		// Spike proves the single-document path only. Reject the multi-file
		// ($_FILES array) shape rather than silently taking the first entry.
		if (is_array($uploadedFile['tmp_name'])) {
			return new JSONResponse(
				['message' => $this->l10n->t('Upload exactly one file')],
				Http::STATUS_UNPROCESSABLE_ENTITY,
			);
		}

		// Ownership is resolved server-side: WE create the temporary user; the
		// browser has no say in which UID owns the resulting file.
		$temporaryUser = $this->accountService->createTemporaryUser();

		try {
			// Defensive invariant, NOT the authorisation: the request is safe
			// because we created this user server-side just above and use that exact
			// object. This re-asserts it really is a GoPaperless temporary user, so a
			// future refactor that ever resolved a browser-supplied UID would fail
			// loudly here instead of silently acting as an arbitrary user.
			if (!$this->accountService->isTemporaryUser($temporaryUser)) {
				throw new LibresignException($this->l10n->t('Resolved user is not a temporary GoPaperless user'));
			}

			$this->fileService->validateUploadedFile($uploadedFile);

			$name = pathinfo((string)$uploadedFile['name'], PATHINFO_FILENAME);

			$result = $this->requestSignatureService->saveFiles([
				'files' => [[
					'uploadedFile' => $uploadedFile,
					'name' => $name,
				]],
				'name' => $name,
				'userManager' => $temporaryUser,
				'settings' => [],
			]);

			$file = $result['file'];

			return new JSONResponse([
				'ownerUserId' => (string)$file->getUserId(),
				'expectedOwnerUserId' => $temporaryUser->getUID(),
				'ownerMatches' => $file->getUserId() === $temporaryUser->getUID(),
				'fileUuid' => (string)$file->getUuid(),
				'nodeId' => (int)$file->getNodeId(),
				'status' => (int)$file->getStatus(),
				'isDraft' => (int)$file->getStatus() === FileStatus::DRAFT->value,
			]);
		} catch (\Throwable $e) {
			// Cleanup-on-failure: a failed upload must not leave an orphan
			// temporary user behind. (Successful uploads intentionally keep the
			// user, which owns the DRAFT — see the lifecycle note in the brief.)
			$this->cleanupTemporaryUser($temporaryUser);

			$this->logger->error('[PublicUploadSpike] Upload failed', ['exception' => $e]);

			return new JSONResponse(
				['message' => $e->getMessage()],
				Http::STATUS_UNPROCESSABLE_ENTITY,
			);
		}
	}

	private function cleanupTemporaryUser(IUser $user): void {
		try {
			$this->accountService->deleteTemporaryUser($user);
		} catch (\Throwable $e) {
			$this->logger->warning('[PublicUploadSpike] Failed to clean up temporary user after a failed upload', [
				'uid' => $user->getUID(),
				'exception' => $e,
			]);
		}
	}
}
