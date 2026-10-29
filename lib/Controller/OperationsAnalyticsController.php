<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\Operations\Analytics\Exception\InvalidRangeException;
use OCA\Libresign\Service\Operations\Analytics\OperationsAccessService;
use OCA\Libresign\Service\Operations\Analytics\OperationsAnalyticsService;
use OCA\Libresign\Service\Operations\Analytics\ValueObject\DateRange;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Operations Analytics endpoints (read-only, aggregate-only).
 *
 * Unlike AdminEntitlementController these use #[NoAdminRequired]: members of
 * the configured operations groups are not Nextcloud admins, so the framework
 * admin gate would lock them out. The authorization boundary is therefore the
 * explicit OperationsAccessService check on every endpoint (feature flag AND
 * admin-or-operations-group), answered with an indistinguishable 403.
 *
 * Order of checks: access gate (403) first, then parameter validation (400),
 * so an unauthorized caller learns nothing about the parameter rules.
 * Query params: from, to (YYYY-MM-DD, inclusive, interpreted in tz), tz
 * (default Africa/Nairobi). Missing from+to = last 30 days.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class OperationsAnalyticsController extends AEnvironmentAwareController {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private OperationsAccessService $access,
		private OperationsAnalyticsService $analytics,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/operations/overview', requirements: ['apiVersion' => '(v1)'])]
	public function overview(string $from = '', string $to = '', string $tz = ''): DataResponse {
		return $this->respond($from, $to, $tz, fn (DateRange $range): array => $this->analytics->overview($range));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/operations/timeseries', requirements: ['apiVersion' => '(v1)'])]
	public function timeseries(string $from = '', string $to = '', string $tz = '', string $metrics = ''): DataResponse {
		return $this->respond($from, $to, $tz, fn (DateRange $range): array => $this->analytics->timeseries($range, $this->parseList($metrics)));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/operations/funnel', requirements: ['apiVersion' => '(v1)'])]
	public function funnel(string $from = '', string $to = '', string $tz = ''): DataResponse {
		return $this->respond($from, $to, $tz, fn (DateRange $range): array => $this->analytics->funnel($range));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/operations/payments', requirements: ['apiVersion' => '(v1)'])]
	public function payments(string $from = '', string $to = '', string $tz = ''): DataResponse {
		return $this->respond($from, $to, $tz, fn (DateRange $range): array => $this->analytics->payments($range));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/operations/entitlements', requirements: ['apiVersion' => '(v1)'])]
	public function entitlements(string $from = '', string $to = '', string $tz = ''): DataResponse {
		return $this->respond($from, $to, $tz, fn (DateRange $range): array => $this->analytics->entitlements($range));
	}

	/**
	 * @param callable(DateRange): array<string, mixed> $build
	 */
	private function respond(string $from, string $to, string $tz, callable $build): DataResponse {
		try {
			$this->access->assertAccess($this->userSession->getUser());
		} catch (LibresignException) {
			return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new DataResponse($build($this->analytics->range($from, $to, $tz)));
		} catch (InvalidRangeException $e) {
			return new DataResponse(['error' => $e->reason], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Operations analytics request failed', ['exception' => $e]);
			return new DataResponse(['error' => 'internal_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * @return list<string>
	 */
	private function parseList(string $csv): array {
		return array_values(array_filter(array_map('trim', explode(',', $csv)), static fn (string $v): bool => $v !== ''));
	}
}
