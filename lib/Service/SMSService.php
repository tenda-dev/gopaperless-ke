<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020-2024 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Exception\LibresignException;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

class SMSService {
	// Configuration keys (no production defaults)
	private const CONFIG_KEY_WEBHOOK_URL = 'webhook_otp_url';
	private const CONFIG_KEY_SECRET = 'webhook_otp_shared_secret';
	private const CONFIG_KEY_LEGACY_SECRET = 'webhook_shared_secret';
	private const CONFIG_KEY_TIARA_API_URL = 'tiara_api_url';

	public function __construct(
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
		private IClientService $httpClientService,
	) {
	}

	/**
	 * Public entry point
	 */
	public function sendSMS(string $phone, string $message): bool {
		$config = $this->getConfig();

		if ($config === null) {
			return false;
		}

		$phone = $this->formatPhone($phone);
		if ($phone === null) {
			return false;
		}

		$payload = $this->buildPayload($phone, $message, $config);

		return $this->send($payload, $config);
	}

	public function testSendSMS(): bool {
		$dummyConfig = [
			'apiKey' => 'test_api_key_123',
			'sender' => 'TESTSENDER',
		];

		$dummyPhone = '254700000000';
		$dummyMessage = 'Test SMS from GoPaperless';

		$payload = [
			'from' => $dummyConfig['sender'],
			'to' => $dummyPhone,
			'message' => $dummyMessage,
		];

		$this->logger->info('[SMS][TEST][REQUEST]', $payload);

		return $this->send($payload, $dummyConfig);
	}

	/**
	 * STEP 1 — Get config
	 */
	private function getConfig(): ?array {
		$apiKey = $this->appConfig->getValueString(Application::APP_ID, 'tiara_api_key', '');
		$sender = $this->appConfig->getValueString(Application::APP_ID, 'tiara_sender_id', '');

		if (empty($apiKey) || empty($sender)) {
			$this->logger->error('[SMS][CONFIG] Missing Tiara credentials', [
				'apiKey' => empty($apiKey),
				'sender' => empty($sender),
			]);
			return null;
		}

		return [
			'apiKey' => $apiKey,
			'sender' => $sender,
		];
	}

	/**
	 * STEP 2 — Format + validate phone
	 */
	private function formatPhone(string $phone): ?string {
		$phone = preg_replace('/\s+/', '', $phone);
		$phone = ltrim($phone, '+');

		if (str_starts_with($phone, '07')) {
			$phone = '254' . substr($phone, 1);
		}

		if (!preg_match('/^2547\d{8}$/', $phone)) {
			$this->logger->error('[SMS][VALIDATION] Invalid phone format', [
				'phone' => $phone,
			]);
			return null;
		}

		return $phone;
	}

	/**
	 * STEP 3 — Build payload
	 */
	private function buildPayload(string $phone, string $message, array $config): array {
		return [
			'from' => $config['sender'],
			'to' => $phone,
			'message' => $message,
			'refId' => uniqid('sms_', true),
		];
	}

	/**
	 * STEP 4 — Send request
	 */
	private function send(array $payload, array $config): bool {
		$apiUrl = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY_TIARA_API_URL, '');
		if ($apiUrl === '') {
			$this->logger->error('[SMS][CONFIG] Tiara API URL is not configured');
			return false;
		}

		try {
			$client = $this->httpClientService->newClient();

			$this->logger->info('[SMS][REQUEST]', $payload);

			$response = $client->post($apiUrl, [
				'headers' => [
					'Authorization' => 'Bearer ' . $config['apiKey'],
					'Content-Type' => 'application/json',
				],
				'body' => json_encode($payload),
				'timeout' => 10,
			]);

			return $this->handleResponse(
				$response->getStatusCode(),
				$response->getBody()
			);

		} catch (\Throwable $e) {
			$this->logger->error('[SMS][ERROR]', [
				'message' => $e->getMessage(),
			]);
			return false;
		}
	}

	/**
	 * STEP 5 — Handle response
	 */
	private function handleResponse(int $statusCode, string $body): bool {
		$this->logger->info('[SMS][RESPONSE]', [
			'status' => $statusCode,
			'body' => $body,
		]);

		if ($statusCode >= 200 && $statusCode < 300) {
			return true;
		}

		$this->logger->error('[SMS][FAILED]', [
			'status' => $statusCode,
			'body' => $body,
		]);

		return false;
	}

	private function isSmsOtpEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'sms_otp_enabled', false);
	}

	/**
	 * Sends the signing code and UUID to the external webhook service.
	 *
	 * @param string $signUuid The per-signer UUID from LibreSign
	 * @param string $code The 6-digit emailToken code
	 * @param string|null $signerEmail The signer's email
	 *
	 * @throws LibresignException
	 */
	public function sendWebhook(string $signUuid, string $code, ?string $signerEmail = null): void {
		$webhookUrl = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY_WEBHOOK_URL, '');
		if ($webhookUrl === '') {
			$this->logger->error('LibreSign Webhook: Webhook URL is not configured.');
			throw new LibresignException('Webhook configuration error', 1);
		}

		// 1. Retrieve the shared secret from AppConfig (System Settings)
		$secret = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY_SECRET, '');
		if ($secret === '') {
			$secret = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY_LEGACY_SECRET, '');
		}

		if (empty($secret)) {
			$this->logger->error('LibreSign Webhook: Shared secret is not configured.');
			throw new LibresignException('Webhook configuration error', 1);
		}

		// 2. Construct the Payload according to requirements
		$payload = [
			'tokens' => [
				[
					'sign_uuid' => $signUuid,
					'code' => $code,
					'signer_email' => $signerEmail,
				]
			]
		];

		// 3. Prepare the HTTP Client
		$client = $this->httpClientService->newClient();

		try {
			// 4. Send POST request
			$response = $client->post($webhookUrl, [
				'headers' => [
					'X-Webhook-Secret' => $secret,
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
				],
				'body' => json_encode($payload),
				'timeout' => 10, // Set a reasonable timeout
			]);

			// 5. Validate Response
			$statusCode = $response->getStatusCode();
			$responseBody = $response->getBody();

			if ($statusCode >= 200 && $statusCode < 300) {
				$decodedResponse = json_decode($responseBody, true);

				if (isset($decodedResponse['status']) && $decodedResponse['status'] === 'ok') {
					$this->logger->info('LibreSign Webhook: Token sent successfully for UUID: ' . $signUuid);
				} else {
					$this->logger->warning('LibreSign Webhook: External server received request but returned non-OK status: ' . $responseBody);
				}
			} else {
				$this->logger->error('LibreSign Webhook: Failed to send token. HTTP Status: ' . $statusCode . ' Body: ' . $responseBody);
				throw new LibresignException('Webhook request failed with status ' . $statusCode, $statusCode);
			}

		} catch (\Exception $e) {
			$this->logger->error('LibreSign Webhook Connection Error: ' . $e->getMessage());
			throw new LibresignException('Could not connect to webhook service', 500, $e);
		}
	}


}
