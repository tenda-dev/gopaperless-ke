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
 * Calls to Tuandikiane, with the base URL and partner key GoPaperless already
 * uses for /external/whatsapp/*: the details form's templates and its answers,
 * and what happened to a draft (sent, signed per signer, completed).
 */
class TuandikianeClient {
	public function __construct(
		private IAppConfig $appConfig,
		private IClientService $http,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Tuandikiane is told, never waited on: a failure is logged and nothing else.
	 */
	public function event(string $draftUuid, string $event, ?string $signer = null): void {
		$body = ['draft_uuid' => $draftUuid, 'event' => $event];
		if ($signer !== null) {
			$body['signer'] = $signer;
		}
		try {
			$answer = $this->request('POST', 'events', $body);
			if ($answer['status'] >= 300) {
				$this->logger->warning('Tuandikiane refused an event', ['event' => $event, 'status' => $answer['status']]);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Tuandikiane did not answer an event', ['event' => $event, 'exception' => $e]);
		}
	}

	/**
	 * The details form behind a form token: its templates and any prefill.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 * @throws \RuntimeException when Tuandikiane can't be reached
	 */
	public function form(string $token): array {
		return $this->request('GET', 'forms/' . rawurlencode($token));
	}

	/**
	 * The filled-in form. 200 carries the review link, 422 the field errors.
	 *
	 * @param array<string, string> $values
	 * @return array{status: int, body: array<string, mixed>}
	 * @throws \RuntimeException when Tuandikiane can't be reached
	 */
	public function submitForm(string $token, string $template, array $values): array {
		// Tuandikiane renders the PDF and creates the draft before it answers.
		return $this->request('POST', 'forms/' . rawurlencode($token), ['template' => $template, 'values' => (object)$values], 30);
	}

	/** @return array{status: int, body: array<string, mixed>} */
	private function request(string $method, string $path, ?array $json = null, int $timeout = 10): array {
		$base = $this->appConfig->getValueString(Application::APP_ID, 'whatsapp_api_url');
		$key = $this->appConfig->getValueString(Application::APP_ID, 'whatsapp_api_key');
		if ($base === '' || $key === '') {
			throw new \RuntimeException('whatsapp_api_url or whatsapp_api_key is unset');
		}
		$options = [
			'headers' => ['X-API-Key' => $key, 'Accept' => 'application/json'],
			'connect_timeout' => 3,
			'timeout' => $timeout,
			'http_errors' => false,
			// The admin sets this URL, and locally it is the host machine.
			'nextcloud' => ['allow_local_address' => true],
		];
		if ($json !== null) {
			$options['json'] = $json;
		}
		$url = rtrim($base, '/') . '/external/tuandikiane/' . $path;
		$client = $this->http->newClient();
		$response = $method === 'GET' ? $client->get($url, $options) : $client->post($url, $options);
		$body = json_decode((string)$response->getBody(), true);
		return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
	}
}
