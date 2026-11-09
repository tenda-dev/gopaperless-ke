<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\TuandikianeService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Tuandikiane's server-to-server door: it creates drafts here with its key
 * (X-Tuandikiane-Key), never with a user's token. The contract is
 * gopaperless docs/tuandikiane-contract.md.
 */
class TuandikianeApiController extends OCSController {
	public function __construct(
		IRequest $request,
		private TuandikianeService $tuandikiane,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Create a draft as the moderator's own, and get its Seal & Deliver link.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'tuandikiane_drafts')]
	#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/tuandikiane/drafts', requirements: ['apiVersion' => '(v1)'])]
	public function create(): DataResponse {
		try {
			$this->tuandikiane->checkKey($this->request->getHeader('X-Tuandikiane-Key'));
		} catch (LibresignException $e) {
			$response = new DataResponse(['message' => $e->getMessage()], $e->getCode());
			if ($e->getCode() === Http::STATUS_UNAUTHORIZED) {
				$response->throttle(['action' => 'tuandikiane_drafts']);
			}
			return $response;
		}
		try {
			return new DataResponse($this->tuandikiane->createDraft($this->request->getParams()));
		} catch (LibresignException $e) {
			$status = $e->getCode() === Http::STATUS_SERVICE_UNAVAILABLE ? Http::STATUS_SERVICE_UNAVAILABLE : Http::STATUS_UNPROCESSABLE_ENTITY;
			return new DataResponse(['message' => $e->getMessage()], $status);
		} catch (\Throwable $e) {
			$this->logger->error('A Tuandikiane draft could not be created', ['exception' => $e]);
			return new DataResponse(['message' => 'The draft could not be created.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * A joiner now has a MIMI account: every slot on the draft with their
	 * number becomes it, and their card goes once the draft is delivered.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'tuandikiane_drafts')]
	#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/tuandikiane/drafts/{draftUuid}/join', requirements: ['apiVersion' => '(v1)'])]
	public function join(string $draftUuid, string $phone = '', string $sub = ''): DataResponse {
		try {
			$this->tuandikiane->checkKey($this->request->getHeader('X-Tuandikiane-Key'));
		} catch (LibresignException $e) {
			$response = new DataResponse(['message' => $e->getMessage()], $e->getCode());
			if ($e->getCode() === Http::STATUS_UNAUTHORIZED) {
				$response->throttle(['action' => 'tuandikiane_drafts']);
			}
			return $response;
		}
		try {
			return new DataResponse(['joined' => $this->tuandikiane->join($draftUuid, $phone, $sub)]);
		} catch (LibresignException $e) {
			return new DataResponse(['message' => $e->getMessage()], in_array($e->getCode(), [Http::STATUS_NOT_FOUND, Http::STATUS_SERVICE_UNAVAILABLE], true) ? $e->getCode() : Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $e) {
			$this->logger->error('A Tuandikiane joiner could not be added', ['exception' => $e]);
			return new DataResponse(['message' => 'The joiner could not be added.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
