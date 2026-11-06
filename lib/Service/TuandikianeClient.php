<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Tells Tuandikiane what happened to a draft: sent, signed (per signer),
 * completed. Same service, base URL and partner key as /external/whatsapp/*.
 * Tuandikiane is told, never waited on: a failure is logged and nothing else.
 */
class TuandikianeClient {
	public function __construct(
		private IAppConfig $appConfig,
		private IClientService $http,
		private LoggerInterface $logger,
	) {
	}

	public function event(string $draftUuid, string $event, ?string $signer = null): void {
		$base = $this->appConfig->getValueString(Application::APP_ID, 'whatsapp_api_url');
		$key = $this->appConfig->getValueString(Application::APP_ID, 'whatsapp_api_key');
		if ($base === '' || $key === '') {
			$this->logger->info('Tuandikiane not told: whatsapp_api_url or whatsapp_api_key is unset', ['event' => $event]);
			return;
		}
		$body = ['draft_uuid' => $draftUuid, 'event' => $event];
		if ($signer !== null) {
			$body['signer'] = $signer;
		}
		try {
			$response = $this->http->newClient()->post(rtrim($base, '/') . '/external/tuandikiane/events', [
				'headers' => ['X-API-Key' => $key, 'Accept' => 'application/json'],
				'json' => $body,
				'connect_timeout' => 3,
				'timeout' => 10,
				'http_errors' => false,
				// The admin sets this URL, and locally it is the host machine.
				'nextcloud' => ['allow_local_address' => true],
			]);
			if ($response->getStatusCode() >= 300) {
				$this->logger->warning('Tuandikiane refused an event', ['event' => $event, 'status' => $response->getStatusCode()]);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Tuandikiane did not answer an event', ['event' => $event, 'exception' => $e]);
		}
	}
}
