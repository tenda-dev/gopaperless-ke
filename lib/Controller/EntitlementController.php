<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Helper\ValidateHelper;
use OCA\Libresign\Service\Entitlement\EntitlementService;
use OCA\Libresign\Service\Sponsorship\SigningCoverageService;
use OCA\Libresign\Service\Sponsorship\SigningSettlementService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class EntitlementController extends AEnvironmentAwareController {

	private EntitlementService $entitlementService;
	private IUserSession $userSession;
	private LoggerInterface $logger;

	public function __construct(
		IRequest $request,
		EntitlementService $entitlementService,
		IUserSession $userSession,
		LoggerInterface $logger,
		private SigningCoverageService $signingCoverageService,
		private SigningSettlementService $signingSettlementService,
		private ValidateHelper $validateHelper,
		private SignRequestMapper $signRequestMapper,
		private IAppConfig $appConfig,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->entitlementService = $entitlementService;
		$this->userSession = $userSession;
		$this->logger = $logger;
	}

	private function assertSponsorshipEnabled(): void {
		if (!$this->appConfig->getValueBool(Application::APP_ID, 'sponsorship_enabled', false)) {
			throw new LibresignException('Sponsorship feature is disabled');
		}
	}

	/**
	 * Check entitlement for current user
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(
		verb: 'GET',
		url: '/api/{apiVersion}/entitlement/sponsorship',
		requirements: ['apiVersion' => '(v1)']
	)]
	public function sponsorship(
		string $productCode,
		int $signRequestId,
	): DataResponse {

		try {
			$user = $this->userSession->getUser();

			if (!$user) {
				return new DataResponse([
					'allowed' => false,
					'error' => 'Unauthorized'
				], Http::STATUS_UNAUTHORIZED);
			}

			if ($signRequestId <= 0) {
				throw new \RuntimeException('signRequestId is required');
			}

			$this->assertSponsorshipEnabled();

			$this->validateHelper->validateSignRequestBelongsToUser(
				$signRequestId,
				$user->getUID(),
			);

			$resolved = $this->signingCoverageService->resolveSigningSponsorship(
				signRequestId: $signRequestId,
			);

			return new DataResponse($resolved->toArray(), Http::STATUS_OK);

		} catch (DoesNotExistException $e) {
			return new DataResponse([
				'allowed' => false,
				'error' => 'Sign request not found',
			], Http::STATUS_NOT_FOUND);
		} catch (LibresignException $e) {
			return new DataResponse([
				'allowed' => false,
				'error' => $e->getMessage(),
			], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {

			$this->logger->error('Entitlement check failed', [
				'exception' => $e
			]);

			return new DataResponse([
				'allowed' => false,
				'error' => 'Unable to verify entitlement'
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}


	/**
	 * Check entitlement for current user
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(
		verb: 'GET',
		url: '/api/{apiVersion}/entitlement/check',
		requirements: ['apiVersion' => '(v1)']
	)]
	public function check(
		string $productCode,
		int $signRequestId,
	): DataResponse {

		try {
			$user = $this->userSession->getUser();

			if (!$user) {
				return new DataResponse([
					'allowed' => false,
					'error' => 'Unauthorized'
				], Http::STATUS_UNAUTHORIZED);
			}

			if ($signRequestId <= 0) {
				throw new \RuntimeException('signRequestId is required');
			}

			if ($productCode === '') {
				return new DataResponse([
					'allowed' => false,
					'error' => 'Invalid product code'
				], Http::STATUS_BAD_REQUEST);
			}

			$this->assertSponsorshipEnabled();

			$this->validateHelper->validateSignRequestBelongsToUser(
				$signRequestId,
				$user->getUID(),
			);

			$resolved = $this->signingCoverageService->resolveSigningCoverage(
				signerUserId: $user->getUID(),
				signRequestId: $signRequestId,
				productCode: $productCode
			);

			return new DataResponse($resolved->toArray(), Http::STATUS_OK);

		} catch (DoesNotExistException $e) {
			return new DataResponse([
				'allowed' => false,
				'error' => 'Sign request not found',
			], Http::STATUS_NOT_FOUND);
		} catch (LibresignException $e) {
			return new DataResponse([
				'allowed' => false,
				'error' => $e->getMessage(),
			], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {

			$this->logger->error('Entitlement check failed', [
				'exception' => $e
			]);

			return new DataResponse([
				'allowed' => false,
				'error' => 'Unable to verify entitlement'
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get available credits for the authenticated user.
	 *
	 * Aggregates all active entitlements for the requested product and returns
	 * the total credits currently available for consumption.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(
		verb: 'GET',
		url: '/api/{apiVersion}/entitlement/credits',
		requirements: ['apiVersion' => '(v1)']
	)]
	public function getAvailableCredits(
		string $productCode,
	): DataResponse {

		try {
			$user = $this->userSession->getUser();

			if (!$user) {
				return new DataResponse([
					'credits' => null,
					'error' => 'Unauthorized',
				], Http::STATUS_UNAUTHORIZED);
			}

			if ($productCode === '') {
				return new DataResponse([
					'credits' => null,
					'error' => 'Invalid product code',
				], Http::STATUS_BAD_REQUEST);
			}

			$credits = $this->entitlementService->getAvailableCredits(
				$user->getUID(),
				$productCode,
			);

			return new DataResponse([
				'credits' => $credits,
			], Http::STATUS_OK);
		} catch (\Throwable $e) {

			$this->logger->error(
				'Failed to fetch available credits',
				[
					'exception' => $e,
					'productCode' => $productCode,
				],
			);

			return new DataResponse([
				'credits' => null,
				'error' => 'Unable to fetch available credits',
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Consume entitlement
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(
		verb: 'POST',
		url: '/api/{apiVersion}/entitlement/consume',
		requirements: ['apiVersion' => '(v1)']
	)]
	public function consume(string $productCode): DataResponse {

		try {
			$user = $this->userSession->getUser();

			if (!$user) {
				return new DataResponse([
					'success' => false,
					'error' => 'Unauthorized'
				], Http::STATUS_UNAUTHORIZED);
			}

			$entitlement = $this->entitlementService->consume(
				$user->getUID(),
				$productCode
			);

			return new DataResponse([
				'success' => true,
				'remainingUses' => $entitlement->getRemainingUses()
			], Http::STATUS_OK);

		} catch (\RuntimeException $e) {

			// expected business failure
			return new DataResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_BAD_REQUEST);

		} catch (\Throwable $e) {

			$this->logger->error('Entitlement consumption failed', [
				'exception' => $e
			]);

			return new DataResponse([
				'success' => false,
				'error' => 'Failed to consume entitlement'
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(
		verb: 'POST',
		url: '/api/{apiVersion}/entitlement/xzy-mspw-cbs',
		requirements: ['apiVersion' => '(v1)']
	)]
	public function consumeAfterSign(
		string $userId,
		string $signUuid,
		string $productCode,
		int $signRequestId,
	): DataResponse {
		try {
			$user = $this->userSession->getUser();

			if (!$user || $user->getUID() !== $userId) {
				throw new \RuntimeException('Unauthorized');
			}

			$userId = $user->getUID();

			if ($productCode === '') {
				throw new \RuntimeException('productCode is required');
			}

			$this->assertSponsorshipEnabled();

			if ($signRequestId <= 0) {
				throw new \RuntimeException('signRequestId is required');
			}

			$signRequest = $this->signRequestMapper->getById($signRequestId);

			if ($signRequest->getUuid() !== $signUuid) {
				throw new LibresignException('Invalid sign request UUID');
			}

			$this->validateHelper->validateSignRequestBelongsToUser(
				$signRequestId,
				$userId,
			);

			$entitlement = $this->signingSettlementService->settle(
				signRequestId: $signRequestId,
				signerUserId: $userId,
				productCode: $productCode,
			);

			return new DataResponse([
				'success' => true,
				'remainingUses' => $entitlement?->getRemainingUses() ?? 0,
			]);

		} catch (DoesNotExistException $e) {
			return new DataResponse([
				'success' => false,
				'error' => 'Sign request not found',
			], Http::STATUS_NOT_FOUND);
		} catch (LibresignException $e) {
			return new DataResponse([
				'success' => false,
				'error' => $e->getMessage(),
			], Http::STATUS_FORBIDDEN);
		} catch (\RuntimeException $e) {
			return new DataResponse([
				'success' => false,
				'error' => $e->getMessage(),
			], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Consume after sign failed', [
				'exception' => $e,
			]);

			return new DataResponse([
				'success' => false,
				'error' => 'Failed to consume entitlement',
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
