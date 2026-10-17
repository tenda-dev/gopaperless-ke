<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\File as FileEntity;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Helper\ValidateHelper;
use OCA\Libresign\Service\WhatsAppService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * The three signer-row actions: WhatsApp, Copy link and Show QR, and the
 * add-signer form's WhatsApp switch. Only someone who could send that signer a
 * reminder may use them, checked the same way.
 */
class WhatsAppController extends AEnvironmentAwareController {
	public function __construct(
		IRequest $request,
		private WhatsAppService $whatsApp,
		private ValidateHelper $validateHelper,
		private SignRequestMapper $signRequestMapper,
		private FileMapper $fileMapper,
		private IUserSession $userSession,
		private IUserManager $userManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Whether a signer can be sent the request on WhatsApp, with their number masked
	 *
	 * @param integer $fileId The identifier value of LibreSign file
	 * @param integer $signRequestId The sign request id
	 * @return DataResponse<Http::STATUS_OK, array{available: bool, masked: ?string, reason: ?string, lastSentAt: ?string, availableAgainAt: ?string, waitReason: ?string, lastError: ?string, chosen: bool}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{message: string}, array{}>
	 *
	 * 200: OK
	 * 401: Not allowed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/whatsapp/{fileId}/{signRequestId}', requirements: ['apiVersion' => '(v1)'])]
	public function recipient(int $fileId, int $signRequestId): DataResponse {
		return $this->guarded($fileId, $signRequestId, fn (FileEntity $file, SignRequest $signRequest)
			=> $this->whatsApp->recipient($file, $signRequest));
	}

	/**
	 * Send a signer the request on WhatsApp
	 *
	 * @param integer $fileId The identifier value of LibreSign file
	 * @param integer $signRequestId The sign request id
	 * @return DataResponse<Http::STATUS_OK, array{status: string, error: ?string, availableAgainAt: ?string, waitReason?: ?string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{message: string}, array{}>
	 *
	 * 200: OK, with the outcome in status
	 * 401: Not allowed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/whatsapp/{fileId}/{signRequestId}', requirements: ['apiVersion' => '(v1)'])]
	public function send(int $fileId, int $signRequestId): DataResponse {
		return $this->guarded($fileId, $signRequestId, fn (FileEntity $file, SignRequest $signRequest, IUser $user)
			=> $this->whatsApp->send($file, $signRequest, $user));
	}

	/**
	 * Whether an account can be sent requests on WhatsApp, for the add-signer form
	 *
	 * @param string $uid The account the sender picked
	 * @return DataResponse<Http::STATUS_OK, array{available: bool, masked: ?string, reason: ?string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{message: string}, array{}>
	 *
	 * 200: OK
	 * 401: Not allowed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/whatsapp/account', requirements: ['apiVersion' => '(v1)'])]
	public function account(string $uid): DataResponse {
		try {
			$this->allowed();
			return new DataResponse($this->whatsApp->account($this->userManager->get($uid)));
		} catch (\Throwable $th) {
			return new DataResponse(['message' => $th->getMessage()], Http::STATUS_UNAUTHORIZED);
		}
	}

	/**
	 * Send this signer the request on WhatsApp when they are asked to sign
	 *
	 * @param integer $fileId The identifier value of LibreSign file
	 * @param integer $signRequestId The sign request id
	 * @param boolean $send Whether to send it on WhatsApp
	 * @param string $to A number to send it to instead of the one on their account
	 * @return DataResponse<Http::STATUS_OK, array{send: bool, masked: ?string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED|Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 200: OK
	 * 401: Not allowed
	 * 422: Not a Kenyan mobile number
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/{apiVersion}/whatsapp/{fileId}/{signRequestId}/choice', requirements: ['apiVersion' => '(v1)'])]
	public function choose(int $fileId, int $signRequestId, bool $send = true, string $to = ''): DataResponse {
		return $this->guarded($fileId, $signRequestId, fn (FileEntity $file, SignRequest $signRequest)
			=> $this->whatsApp->choose($signRequest, $send, $to));
	}

	/**
	 * A signer's signing link and its QR code
	 *
	 * @param integer $fileId The identifier value of LibreSign file
	 * @param integer $signRequestId The sign request id
	 * @return DataResponse<Http::STATUS_OK, array{url: string, qr: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{message: string}, array{}>
	 *
	 * 200: OK
	 * 401: Not allowed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/sign-link/{fileId}/{signRequestId}', requirements: ['apiVersion' => '(v1)'])]
	public function signLink(int $fileId, int $signRequestId): DataResponse {
		return $this->guarded($fileId, $signRequestId, function (FileEntity $file, SignRequest $signRequest): array {
			$url = $this->whatsApp->signLink($signRequest);
			return ['url' => $url, 'qr' => $this->whatsApp->qr($url)];
		});
	}

	/** Signed in, the feature on, and allowed to send documents for signature. */
	private function allowed(): IUser {
		$user = $this->userSession->getUser();
		if (!$user instanceof IUser) {
			throw new \RuntimeException('Sign in first.');
		}
		if (!$this->whatsApp->enabled()) {
			throw new \RuntimeException('WhatsApp signing requests are off.');
		}
		$this->validateHelper->canRequestSign($user);
		return $user;
	}

	private function guarded(int $fileId, int $signRequestId, callable $action): DataResponse {
		try {
			$user = $this->allowed();
			$this->validateHelper->validateLibreSignFileId($fileId);
			$signRequest = $this->signRequestMapper->getByFileIdAndSignRequestId($fileId, $signRequestId);
			$this->validateHelper->iRequestedSignThisFile($user, $fileId);
			return new DataResponse($action($this->fileMapper->getById($fileId), $signRequest, $user));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $th) {
			return new DataResponse(['message' => $th->getMessage()], Http::STATUS_UNAUTHORIZED);
		}
	}
}
