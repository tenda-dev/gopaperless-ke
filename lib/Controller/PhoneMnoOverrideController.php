<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\Payment\PhoneOverrideAdminService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Admin-only management of phone-number exceptions (Safaricom overrides).
 *
 * Authorization: these endpoints carry NO #[NoAdminRequired], so Nextcloud's
 * security middleware requires a site administrator — the same gate every
 * other LibreSign admin-settings endpoint relies on. The UI visibility is not
 * the control; this backend check is.
 *
 * This controller contains NO DPO/Daraja/rail logic. It only delegates to
 * PhoneOverrideAdminService, which writes MNO identity ('safaricom'); the
 * routing decision stays with PhoneMnoResolver -> MnoRoutingRegistry.
 */
class PhoneMnoOverrideController extends AEnvironmentAwareController {
	public function __construct(
		IRequest $request,
		private PhoneOverrideAdminService $service,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * List all configured phone exceptions.
	 */
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/admin/phone-overrides', requirements: ['apiVersion' => '(v1)'])]
	public function index(): DataResponse {
		return new DataResponse(['overrides' => $this->service->listAll()]);
	}

	/**
	 * Create (or reactivate) a Safaricom exception for a phone number.
	 *
	 * @param string $phone Phone number in international format (e.g. +2547XXXXXXXX)
	 */
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/admin/phone-overrides', requirements: ['apiVersion' => '(v1)'])]
	public function create(string $phone = ''): DataResponse {
		$createdBy = $this->userSession->getUser()?->getUID();

		try {
			$result = $this->service->createSafaricomException($phone, $createdBy);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('[PhoneOverride] create failed', ['error' => $e->getMessage()]);
			return new DataResponse(
				['error' => 'Could not save the phone exception.'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		if ($result['status'] === 'duplicate') {
			return new DataResponse(
				[
					'error' => 'This phone number already has an exception configured.',
					'override' => $result['override'],
				],
				Http::STATUS_CONFLICT,
			);
		}

		return new DataResponse($result);
	}

	/**
	 * Edit an exception: change its phone number and/or its active state.
	 *
	 * @param int $id Exception id
	 * @param string|null $phone New phone number (optional)
	 * @param bool|null $active New active state (optional)
	 */
	#[ApiRoute(verb: 'PATCH', url: '/api/{apiVersion}/admin/phone-overrides/{id}', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function update(int $id, ?string $phone = null, ?bool $active = null): DataResponse {
		try {
			$result = $this->service->update($id, $phone, $active);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'Phone exception not found.'], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('[PhoneOverride] update failed', ['error' => $e->getMessage()]);
			return new DataResponse(
				['error' => 'Could not update the phone exception.'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		if ($result['status'] === 'duplicate') {
			return new DataResponse(
				[
					'error' => 'Another exception already uses this phone number.',
					'override' => $result['override'],
				],
				Http::STATUS_CONFLICT,
			);
		}

		return new DataResponse($result);
	}

	/**
	 * Permanently delete an exception.
	 *
	 * @param int $id Exception id
	 */
	#[ApiRoute(verb: 'DELETE', url: '/api/{apiVersion}/admin/phone-overrides/{id}', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function destroy(int $id): DataResponse {
		try {
			$this->service->delete($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'Phone exception not found.'], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			$this->logger->error('[PhoneOverride] delete failed', ['error' => $e->getMessage()]);
			return new DataResponse(
				['error' => 'Could not delete the phone exception.'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		return new DataResponse(['success' => true, 'id' => $id]);
	}
}
