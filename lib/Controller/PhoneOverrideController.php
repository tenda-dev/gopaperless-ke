<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\Payment\PhoneOverrideAdminService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Admin-only management of phone-number payment routing overrides.
 *
 * All endpoints are gated by the `phone_mno_routing_v2_enabled` feature flag
 * and require the caller to be an admin.
 */
class PhoneOverrideController extends AEnvironmentAwareController {
	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private PhoneOverrideAdminService $phoneOverrideAdminService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function assertFeatureEnabled(): void {
		if (!$this->appConfig->getValueBool(Application::APP_ID, 'phone_mno_routing_v2_enabled', false)) {
			throw new LibresignException($this->l10n->t('Phone MNO routing v2 is disabled'), Http::STATUS_FORBIDDEN);
		}
	}

	private function assertAdminUser(): void {
		if (!$this->groupManager->isAdmin($this->userSession->getUser()?->getUID() ?? '')) {
			throw new LibresignException($this->l10n->t('Unauthorized'), Http::STATUS_FORBIDDEN);
		}
	}

	/**
	 * List phone number payment routing overrides.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{overrides: array<int, array<string, mixed>>}, array{}>
	 *
	 * 200: Overrides returned
	 */
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/admin/phone-overrides', requirements: ['apiVersion' => '(v1)'])]
	public function getPhoneOverrides(): DataResponse {
		$this->assertFeatureEnabled();
		$this->assertAdminUser();

		return new DataResponse([
			'overrides' => $this->phoneOverrideAdminService->listAll(),
		]);
	}

	/**
	 * Create a phone number payment routing override.
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_CONFLICT, array{error: string, override: array<string, mixed>}, array{}>
	 *
	 * 200: Override created or reactivated
	 * 400: Invalid input
	 * 409: Active duplicate
	 */
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/admin/phone-overrides', requirements: ['apiVersion' => '(v1)'])]
	public function createPhoneOverride(
		string $phone,
		string $mno,
		string $provider,
	): DataResponse {
		$this->assertFeatureEnabled();
		$this->assertAdminUser();

		try {
			$result = $this->phoneOverrideAdminService->createException(
				$phone,
				$mno,
				$provider,
				$this->userSession->getUser()?->getUID(),
			);

			if ($result['status'] === 'duplicate') {
				return new DataResponse(
					[
						'error' => $this->l10n->t('An override already exists for this phone number.'),
						'override' => $result['override'],
					],
					Http::STATUS_CONFLICT
				);
			}

			return new DataResponse($result);
		} catch (\InvalidArgumentException $e) {
			$this->logger->warning('Phone override creation failed: {message}', [
				'message' => $e->getMessage(),
				'exception' => $e,
			]);

			return new DataResponse(
				['error' => $this->l10n->t('Invalid phone number, mobile network, or provider.')],
				Http::STATUS_BAD_REQUEST
			);
		}
	}

	/**
	 * Update a phone number payment routing override.
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>|DataResponse<Http::STATUS_CONFLICT, array{error: string, override: array<string, mixed>}, array{}>
	 *
	 * 200: Override updated
	 * 400: Invalid input
	 * 404: Override not found
	 * 409: Phone number already belongs to another override
	 */
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'PATCH', url: '/api/{apiVersion}/admin/phone-overrides/{id}', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function updatePhoneOverride(
		int $id,
		?string $phone = null,
		?string $mno = null,
		?string $provider = null,
		?bool $active = null,
	): DataResponse {
		$this->assertFeatureEnabled();
		$this->assertAdminUser();

		try {
			$result = $this->phoneOverrideAdminService->update(
				$id,
				$phone,
				$mno,
				$provider,
				$active,
			);

			if ($result['status'] === 'duplicate') {
				return new DataResponse(
					[
						'error' => $this->l10n->t('An override already exists for this phone number.'),
						'override' => $result['override'],
					],
					Http::STATUS_CONFLICT
				);
			}

			return new DataResponse($result);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			$this->logger->warning('Phone override update failed: not found', [
				'id' => $id,
				'exception' => $e,
			]);

			return new DataResponse(
				['error' => $this->l10n->t('Phone number override not found.')],
				Http::STATUS_NOT_FOUND
			);
		} catch (\InvalidArgumentException $e) {
			$this->logger->warning('Phone override update failed: {message}', [
				'message' => $e->getMessage(),
				'exception' => $e,
			]);

			return new DataResponse(
				['error' => $this->l10n->t('Invalid phone number, mobile network, or provider.')],
				Http::STATUS_BAD_REQUEST
			);
		}
	}

	/**
	 * Delete a phone number payment routing override.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{status: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 *
	 * 200: Override deleted
	 * 404: Override not found
	 */
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/{apiVersion}/admin/phone-overrides/{id}', requirements: ['apiVersion' => '(v1)', 'id' => '\d+'])]
	public function deletePhoneOverride(int $id): DataResponse {
		$this->assertFeatureEnabled();
		$this->assertAdminUser();

		try {
			$this->phoneOverrideAdminService->delete($id);

			return new DataResponse([
				'status' => 'deleted',
			]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			$this->logger->warning('Phone override deletion failed: not found', [
				'id' => $id,
				'exception' => $e,
			]);

			return new DataResponse(
				['error' => $this->l10n->t('Phone number override not found.')],
				Http::STATUS_NOT_FOUND
			);
		}
	}
}
