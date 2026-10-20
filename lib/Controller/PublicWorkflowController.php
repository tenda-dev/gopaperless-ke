<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\PublicWorkflow;
use OCA\Libresign\Enum\FileStatus;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Helper\ValidateHelper;
use OCA\Libresign\Service\FileService;
use OCA\Libresign\Service\PublicWorkflowService;
use OCA\Libresign\Service\AccountService;
use OCA\Libresign\Service\RequesterReconciliationService;
use OCA\Libresign\Service\RequestSignatureService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Public, anonymous document-preparation workflow.
 *
 * Invariants:
 *  - Inert unless public_workflow_enabled is on.
 *  - The owner is resolved server-side from the capability cookie; the browser
 *    never supplies a UID and never sees the raw token.
 *  - The target document comes from the resolved workflow row, never a
 *    request-supplied file id/uuid, so one workflow cannot touch another's file.
 *  - Mutations keep Nextcloud CSRF on (no #[NoCSRFRequired]) and fail closed.
 *  - DRAFT-only; no publish/sign/notify path is invoked.
 *
 * Reuses the existing save/validate layer with the temporary IUser passed
 * explicitly; no business logic is duplicated here.
 */
class PublicWorkflowController extends AEnvironmentAwareController {
	public function __construct(
		IRequest $request,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
		private PublicWorkflowService $workflowService,
		private FileService $fileService,
		private RequestSignatureService $requestSignatureService,
		private ValidateHelper $validateHelper,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private RequesterReconciliationService $reconciliationService,
		private AccountService $accountService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Start a new public workflow. Creates the temporary owner server-side and
	 * sets the HttpOnly capability cookie. The raw token is returned ONLY via
	 * the cookie, never in the body.
	 */
	#[NoAdminRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 5, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/public-workflow', requirements: ['apiVersion' => '(v1)'])]
	public function create(): DataResponse {
		if ($disabled = $this->disabledResponse()) {
			return $disabled;
		}
		try {
			$created = $this->workflowService->create();
			$workflow = $created['workflow'];

			$response = new DataResponse([
				'status' => $workflow->getStatus(),
				'expiresAt' => $workflow->getExpiresAt()->format(\DateTimeInterface::ATOM),
				'hasDocument' => false,
			]);
			$this->setCapabilityCookie($response, $created['rawToken'], $workflow->getExpiresAt());
			return $response;
		} catch (\Throwable $e) {
			$this->logger->error('[PublicWorkflow] create failed', ['exception' => $e]);
			return $this->error($this->l10n->t('Could not start the workflow'));
		}
	}

	/**
	 * Resume / status. Safe read (CSRF not required for GET).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/public-workflow', requirements: ['apiVersion' => '(v1)'])]
	public function resume(): DataResponse {
		if ($disabled = $this->disabledResponse()) {
			return $disabled;
		}
		try {
			$workflow = $this->workflowService->resolveFromToken($this->capabilityFromCookie());
			$hasDocument = $this->workflowService->hasDocument($workflow);
			return new DataResponse([
				'status' => $workflow->getStatus(),
				'expiresAt' => $workflow->getExpiresAt()->format(\DateTimeInterface::ATOM),
				'hasDocument' => $hasDocument,
				'fileUuid' => $workflow->getFileUuid(),
				// Requester self-signing metadata so the public UI can rehydrate the
				// requester chip + email after a reload (DRAFT metadata only).
				'requesterEmail' => $workflow->getRequesterEmail(),
				'requesterIsSigner' => $workflow->getRequesterIsSigner(),
				// DRAFT document with its signers + visible elements, so the client
				// can restore the prepared state after a reload. Null when absent or
				// if formatting fails (never leaks internals, never fails resume).
				'document' => $hasDocument ? $this->formattedDocument($workflow) : null,
			]);
		} catch (LibresignException) {
			return $this->error($this->l10n->t('Invalid or expired workflow'), Http::STATUS_UNAUTHORIZED);
		}
	}

	/**
	 * Serve the workflow's DRAFT PDF using its capability cookie.
	 *
	 * This endpoint is read-only and serves only the workflow's own document.
	 * Generic validation routes require an authenticated user and must not be
	 * weakened to support anonymous access.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/p/workflow/pdf')]
	public function documentPdf(): FileDisplayResponse|DataResponse {
		if ($disabled = $this->disabledResponse()) {
			return $disabled;
		}
		try {
			$workflow = $this->workflowService->resolveFromToken($this->capabilityFromCookie());
		} catch (LibresignException) {
			return $this->error($this->l10n->t('Invalid or expired workflow'), Http::STATUS_UNAUTHORIZED);
		}
		try {
			$file = $this->workflowService->getDocumentFile($workflow);
		} catch (LibresignException) {
			// Generic 404: do not reveal whether a document/node exists.
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			$this->logger->warning('[PublicWorkflow] could not serve document pdf', ['exception' => $e]);
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $file->getMimeType()]);
	}

	/**
	 * Upload the one document for this workflow, owned by the temporary user.
	 * Rejects a second upload (one document per workflow, enforced server-side).
	 */
	#[NoAdminRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/public-workflow/document', requirements: ['apiVersion' => '(v1)'])]
	public function uploadDocument(): DataResponse {
		if ($disabled = $this->disabledResponse()) {
			return $disabled;
		}
		try {
			$workflow = $this->workflowService->resolveFromToken($this->capabilityFromCookie());
		} catch (LibresignException) {
			return $this->error($this->l10n->t('Invalid or expired workflow'), Http::STATUS_UNAUTHORIZED);
		}

		if ($this->workflowService->hasDocument($workflow)) {
			return $this->error($this->l10n->t('This workflow already has a document'));
		}

		$uploadedFile = $this->request->getUploadedFile('file');
		if (!$uploadedFile || !isset($uploadedFile['tmp_name']) || is_array($uploadedFile['tmp_name'])) {
			return $this->error($this->l10n->t('Upload exactly one file'));
		}

		try {
			$name = pathinfo((string)$uploadedFile['name'], PATHINFO_FILENAME);
			// Create the document under the workflow lock to prevent concurrent uploads.
			// The owner is resolved from the locked workflow.
			$file = $this->workflowService->createDocument($workflow, function (IUser $owner) use ($uploadedFile, $name) {
				$this->fileService->validateUploadedFile($uploadedFile);
				$result = $this->requestSignatureService->saveFiles([
					'files' => [[
						'uploadedFile' => $uploadedFile,
						'name' => $name,
					]],
					'name' => $name,
					'userManager' => $owner,
					'settings' => [],
				]);
				return $result['file'];
			});

			return new DataResponse([
				'fileUuid' => (string)$file->getUuid(),
				'nodeId' => (int)$file->getNodeId(),
				'status' => (int)$file->getStatus(),
				'isDraft' => (int)$file->getStatus() === FileStatus::DRAFT->value,
				'ownerMatches' => $file->getUserId() === $workflow->getOwnerUid(),
			]);
		} catch (LibresignException $e) {
			return $this->error($e->getMessage());
		} catch (\Throwable $e) {
			$this->logger->error('[PublicWorkflow] upload failed', ['exception' => $e]);
			return $this->error($this->l10n->t('Could not upload the document'));
		}
	}

	/**
	 * Prepare signers on the workflow's DRAFT document. No publish/notify: the
	 * file stays DRAFT and canNotifySigners() gates notifications off at DRAFT.
	 */
	#[NoAdminRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/public-workflow/signers', requirements: ['apiVersion' => '(v1)'])]
	public function saveSigners(array $signers = []): DataResponse {
		return $this->prepare(function (IUser $owner, string $fileUuid) use ($signers): array {
			$this->validateHelper->validateExistingFile([
				'uuid' => $fileUuid,
				'userManager' => $owner,
			]);
			return [
				'uuid' => $fileUuid,
				'signers' => $signers,
				'userManager' => $owner,
				'status' => FileStatus::DRAFT->value,
			];
		});
	}

	/**
	 * Prepare visible signature elements on the workflow's DRAFT document, via
	 * the parameterized save path (NOT the session-coupled FileElementController).
	 * Persistence only — no publish/sign/notify.
	 */
	#[NoAdminRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/public-workflow/visible-elements', requirements: ['apiVersion' => '(v1)'])]
	public function saveVisibleElements(array $visibleElements = []): DataResponse {
		return $this->prepare(function (IUser $owner, string $fileUuid, PublicWorkflow $workflow) use ($visibleElements): array {
			$this->validateHelper->validateExistingFile([
				'uuid' => $fileUuid,
				'userManager' => $owner,
			]);
			// Bind elements to this workflow's document to prevent cross-workflow access.
			$elements = $this->workflowService->assertWorkflowElements($workflow, $visibleElements);
			if (!empty($elements)) {
				$this->validateHelper->validateVisibleElements($elements, ValidateHelper::TYPE_VISIBLE_ELEMENT_PDF);
			}
			return [
				'uuid' => $fileUuid,
				'visibleElements' => $elements,
				'userManager' => $owner,
				'status' => FileStatus::DRAFT->value,
			];
		});
	}

	/**
	 * Revoke the workflow and clear the capability cookie.
	 */
	#[NoAdminRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'DELETE', url: '/api/{apiVersion}/public-workflow', requirements: ['apiVersion' => '(v1)'])]
	public function revoke(): DataResponse {
		if ($disabled = $this->disabledResponse()) {
			return $disabled;
		}
		try {
			$workflow = $this->workflowService->resolveFromToken($this->capabilityFromCookie());
			$this->workflowService->revoke($workflow);
		} catch (LibresignException) {
			// Idempotent from the caller's view; still clear the cookie.
		}
		$response = new DataResponse(['revoked' => true]);
		$response->invalidateCookie(PublicWorkflowService::COOKIE_NAME);
		return $response;
	}

	/**
	 * Delete one visible signature element from the workflow's DRAFT document. CSRF
	 * stays on. Owner, document and element association are verified server-side;
	 * the browser supplies only the numeric element id, checked to belong to this
	 * workflow's document. Returns the refreshed document.
	 */
	#[NoAdminRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'DELETE', url: '/api/{apiVersion}/public-workflow/visible-elements/{elementId}', requirements: ['apiVersion' => '(v1)', 'elementId' => '\\d+'])]
	public function deleteVisibleElement(int $elementId): DataResponse {
		if ($disabled = $this->disabledResponse()) {
			return $disabled;
		}
		try {
			$workflow = $this->workflowService->resolveFromToken($this->capabilityFromCookie());
		} catch (LibresignException) {
			return $this->error($this->l10n->t('Invalid or expired workflow'), Http::STATUS_UNAUTHORIZED);
		}
		try {
			$this->workflowService->deleteVisibleElement($workflow, $elementId);
		} catch (LibresignException $e) {
			return $this->error($e->getMessage());
		} catch (\Throwable $e) {
			$this->logger->error('[PublicWorkflow] delete visible element failed', ['exception' => $e]);
			return $this->error($this->l10n->t('Could not update the workflow'));
		}
		return new DataResponse(['document' => $this->formattedDocument($workflow)]);
	}

	/**
	 * Save the requester's email and self-signing intent on the active workflow.
	 *
	 * Captures these details during DRAFT preparation, before the sign-in and claim
	 * handoff. The email is used for display and identity matching only; it does
	 * not authorize a workflow claim. CSRF protection remains enabled.
	 */
	#[NoAdminRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/public-workflow/requester', requirements: ['apiVersion' => '(v1)'])]
	public function saveRequester(?string $email = null, bool $isSigner = false): DataResponse {
		if ($disabled = $this->disabledResponse()) {
			return $disabled;
		}
		try {
			$workflow = $this->workflowService->resolveFromToken($this->capabilityFromCookie());
		} catch (LibresignException) {
			return $this->error($this->l10n->t('Invalid or expired workflow'), Http::STATUS_UNAUTHORIZED);
		}
		try {
			$this->workflowService->saveRequesterIdentity($workflow, $email, $isSigner);
		} catch (\Throwable $e) {
			$this->logger->error('[PublicWorkflow] could not save requester identity', ['exception' => $e]);
			return $this->error($this->l10n->t('Could not save your details'));
		}
		return new DataResponse(['saved' => true]);
	}

	/**
	 * Check whether the authenticated user can claim the workflow.
	 *
	 * Resolves the workflow from the capability cookie. The email comparison is
	 * provided for the confirmation UI only; it does not authorize the claim.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/public-workflow/claim', requirements: ['apiVersion' => '(v1)'])]
	public function claimStatus(): DataResponse {
		if ($disabled = $this->disabledResponse()) {
			return $disabled;
		}
		$user = $this->userSession->getUser();
		if (!$user instanceof IUser) {
			return $this->error($this->l10n->t('Authentication required'), Http::STATUS_UNAUTHORIZED);
		}
		try {
			$workflow = $this->workflowService->resolveForClaim($this->capabilityFromCookie());
		} catch (LibresignException) {
			// No claimable workflow for this browser — a normal, non-error state.
			return new DataResponse(['claimable' => false]);
		}
		$info = $this->workflowService->describeClaim($workflow, $user, $user->getEMailAddress());
		return new DataResponse([
			'claimable' => $info['claimable'],
			'status' => $workflow->getStatus(),
			'requesterEmail' => $info['requesterEmail'],
			'accountEmail' => $user->getEMailAddress(),
			'emailMatches' => $info['emailMatches'],
			'alreadyClaimedByMe' => $info['alreadyClaimedByMe'],
		]);
	}

	/**
	 * Claim the workflow after explicit confirmation by the authenticated user.
	 *
	 * Claim authorization requires both a valid capability and an authenticated
	 * session. An email match is neither required nor sufficient. CSRF protection
	 * remains enabled.
	 *
	 * Transfers document ownership and returns the signing-preparation redirect.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/public-workflow/claim', requirements: ['apiVersion' => '(v1)'])]
	public function claim(): DataResponse {
		if ($disabled = $this->disabledResponse()) {
			return $disabled;
		}
		$user = $this->userSession->getUser();
		if (!$user instanceof IUser) {
			return $this->error($this->l10n->t('Authentication required'), Http::STATUS_UNAUTHORIZED);
		}
		try {
			$workflow = $this->workflowService->resolveForClaim($this->capabilityFromCookie());
		} catch (LibresignException) {
			return $this->error($this->l10n->t('Invalid or expired workflow'), Http::STATUS_UNAUTHORIZED);
		}
		try {
			$file = $this->workflowService->claim($workflow, $user);
		} catch (LibresignException $e) {
			// Concurrency / already-claimed / not-ready → actionable conflict.
			return $this->error($e->getMessage(), Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			$this->logger->error('[PublicWorkflow] claim failed', ['exception' => $e]);
			return $this->error($this->l10n->t('Could not claim the document'));
		}
		$uuid = (string)$file->getUuid();
		// Recover a missing account email before reconciliation; this recovery is
		// idempotent and does not affect the already-completed claim.
		$this->accountService->recoverMissingEmailFromUid($user);

		// Reconcile other eligible email signers on this file. Login-time
		// reconciliation handles signers who become eligible later.
		$reconciliation = $this->reconciliationService->reconcile($workflow, $user);

		// After the requester, map every OTHER provisional email signer on the file
		// whose email resolves to a trusted account (exact match, trusted-only,
		// in place). Login self-reconciliation catches any that appear later.
		$signerReconciliation = $this->reconciliationService->reconcileFileSigners((int)$file->getId());
		$redirectUrl = $this->urlGenerator->linkToRoute('libresign.page.indexFPath', ['path' => 'filelist/sign'])
			. '?uuid=' . urlencode($uuid);
		$response = new DataResponse(['uuid' => $uuid, 'redirectUrl' => $redirectUrl, 'reconciliation' => $reconciliation, 'signerReconciliation' => $signerReconciliation]);

		// Invalidate the capability after claiming so this browser can no longer
		// use it to access the workflow anonymously.
		$response->invalidateCookie(PublicWorkflowService::COOKIE_NAME);
		return $response;
	}

	/**
	 * Run the shared DRAFT preparation flow for the workflow's document.
	 *
	 * Resolves the workflow and owner, builds the save payload, and uses the
	 * existing request-signature save pipeline without publishing or triggering
	 * signing or notifications.
	 *
	 * @param callable(IUser, string): array $buildData
	 */
	private function prepare(callable $buildData): DataResponse {
		if ($disabled = $this->disabledResponse()) {
			return $disabled;
		}
		try {
			$workflow = $this->workflowService->resolveFromToken($this->capabilityFromCookie());
		} catch (LibresignException) {
			return $this->error($this->l10n->t('Invalid or expired workflow'), Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->workflowService->hasDocument($workflow)) {
			return $this->error($this->l10n->t('Upload a document first'));
		}
		try {
			$owner = $this->workflowService->resolveOwner($workflow);
			$fileUuid = (string)$workflow->getFileUuid();
			$data = $buildData($owner, $fileUuid, $workflow);

			$this->requestSignatureService->save($data);

			// Return the same document structure used by resume and delete responses,
			// including per-page dimensions required by the editor.
			return new DataResponse($this->formattedDocument($workflow));
		} catch (LibresignException $e) {
			return $this->error($e->getMessage());
		} catch (\Throwable $e) {
			$this->logger->error('[PublicWorkflow] preparation failed', ['exception' => $e]);
			return $this->error($this->l10n->t('Could not update the workflow'));
		}
	}

	/**
	 * Format the workflow's DRAFT document for the preparation editor.
	 *
	 * Includes signers and visible elements in the same structure as the
	 * authenticated file-show response. Resolves the owner and UUID from the
	 * workflow; logs formatting failures and returns null rather than exposing
	 * internal errors.
	 */
	private function formattedDocument(PublicWorkflow $workflow): ?array {
		try {
			$owner = $this->workflowService->resolveOwner($workflow);
			$fileUuid = (string)$workflow->getFileUuid();
			$this->validateHelper->validateExistingFile([
				'uuid' => $fileUuid,
				'userManager' => $owner,
			]);
			return $this->fileService
				->setFileByUuid($fileUuid)
				->setMe($owner)
				->showSigners()
				->showVisibleElements()
				->toArray();
		} catch (\Throwable $e) {
			$this->logger->warning('[PublicWorkflow] resume could not format document', ['exception' => $e]);
			return null;
		}
	}

	private function capabilityFromCookie(): ?string {
		return $this->request->getCookie(PublicWorkflowService::COOKIE_NAME);
	}

	/**
	 * Set the capability cookie. The OCP cookie API controls only
	 * name/value/expiry/SameSite; Nextcloud's emitter applies HttpOnly, Secure and
	 * the webroot path.
	 */
	private function setCapabilityCookie(DataResponse $response, string $rawToken, \DateTimeInterface $expiresAt): void {
		$response->addCookie(
			PublicWorkflowService::COOKIE_NAME,
			$rawToken,
			\DateTime::createFromInterface($expiresAt),
			'Lax',
		);
	}

	private function disabledResponse(): ?DataResponse {
		if (!$this->appConfig->getValueBool(Application::APP_ID, 'public_workflow_enabled', false)) {
			return $this->error($this->l10n->t('Public workflow is disabled'), Http::STATUS_NOT_FOUND);
		}
		return null;
	}

	private function error(string $message, int $status = Http::STATUS_UNPROCESSABLE_ENTITY): DataResponse {
		return new DataResponse(['message' => $message], $status);
	}
}
