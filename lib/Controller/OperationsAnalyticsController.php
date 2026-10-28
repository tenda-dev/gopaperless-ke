<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\Operations\Analytics\OperationsAccessService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Operations Analytics endpoints (Phase 1: inert authorization scaffolding).
 *
 * Unlike AdminEntitlementController these use #[NoAdminRequired]: members of
 * the configured operations groups are not Nextcloud admins, so the framework
 * admin gate would lock them out. The authorization boundary is therefore the
 * explicit OperationsAccessService check on every endpoint (feature flag AND
 * admin-or-operations-group), answered with an indistinguishable 403.
 *
 * No queries run yet: authorized callers receive a placeholder body.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class OperationsAnalyticsController extends AEnvironmentAwareController {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private OperationsAccessService $access,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/operations/overview', requirements: ['apiVersion' => '(v1)'])]
	public function overview(): DataResponse {
		return $this->respond();
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/operations/timeseries', requirements: ['apiVersion' => '(v1)'])]
	public function timeseries(): DataResponse {
		return $this->respond();
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/operations/funnel', requirements: ['apiVersion' => '(v1)'])]
	public function funnel(): DataResponse {
		return $this->respond();
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/operations/payments', requirements: ['apiVersion' => '(v1)'])]
	public function payments(): DataResponse {
		return $this->respond();
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/operations/entitlements', requirements: ['apiVersion' => '(v1)'])]
	public function entitlements(): DataResponse {
		return $this->respond();
	}

	/**
	 * Run the access gate; 403 on any failure, placeholder body otherwise.
	 */
	private function respond(): DataResponse {
		try {
			$this->access->assertAccess($this->userSession->getUser());
		} catch (LibresignException) {
			return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}
		return new DataResponse(['meta' => ['placeholder' => true], 'metrics' => new \stdClass()]);
	}
}
