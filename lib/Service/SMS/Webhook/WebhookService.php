<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\SMS\Webhook;

use OCA\Libresign\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

final class WebhookService
{
	private const DEFAULT_WEBHOOK_URL =
	'https://gopaperless.astralyngroup.com/webhooks/gopaperless/email-tokens';

	private const CONFIG_KEY_WEBHOOK_URL =
	'webhook_otp_url';

	private const CONFIG_KEY_WEBHOOK_SECRET =
	'webhook_otp_shared_secret';

	private const CONFIG_KEY_WEBHOOK_ENABLED =
	'webhook_otp_enabled';

	private const REQUEST_TIMEOUT_SECONDS = 10;

	public function __construct(
		private IAppConfig $appConfig,
		private IClientService $httpClientService,
		private LoggerInterface $logger,
	) {}

	/**
	 * Sends signing OTP details to an external webhook.
	 *
	 * Responsibilities:
	 * - Retrieve webhook configuration
	 * - Build the webhook payload
	 * - Execute the HTTP request
	 * - Validate the webhook response
	 *
	 * This integration is considered best effort and
	 * must never interrupt the signing flow.
	 */
	public function sendCodeToSign(
		string $code,
		?string $signUuid,
		?string $signerEmail = null,
	): bool {

		if (!$this->isWebhookOtpEnabled()) {
			return false;
		}

		if ($signUuid === null) {

			$this->logger->debug(
				'[Webhook] Skipping webhook OTP because sign UUID is unavailable',
				[
					'app' => Application::APP_ID,
				]
			);

			return false;
		}

		$webhookUrl = $this->getWebhookUrl();
		$secret = $this->getWebhookSecret();

		if ($secret === null) {

			$this->logger->error(
				'[Webhook] Missing webhook secret',
				[
					'app' => Application::APP_ID,
				]
			);

			return false;
		}

		$payload = [
			'tokens' => [
				[
					'sign_uuid' => $signUuid,
					'code' => $code,
					'signer_email' => $signerEmail,
				],
			],
		];

		try {
			$client = $this->httpClientService
				->newClient();

			$response = $client->post(
				$webhookUrl,
				[
					'headers' => [
						'X-Webhook-Secret' => $secret,
						'Accept' => 'application/json',
						'Content-Type' => 'application/json',
					],
					'body' => json_encode(
						$payload,
						JSON_THROW_ON_ERROR,
					),
					'timeout' => self::REQUEST_TIMEOUT_SECONDS,
				]
			);

			return $this->handleResponse(
				$response->getStatusCode(),
				$response->getBody(),
				$signUuid,
			);
		} catch (Throwable $e) {

			$this->logger->warning(
				'[Webhook] Failed to send signing token',
				[
					'signUuid' => $signUuid,
					'error' => $e->getMessage(),
					'app' => Application::APP_ID,
				]
			);

			return false;
		}
	}

	/**
	 * Determines whether the webhook accepted the request.
	 *
	 * Success criteria:
	 * - HTTP 2xx response
	 * - Response status = ok
	 */
	private function handleResponse(
		int $statusCode,
		string $body,
		string $signUuid,
	): bool {

		if (
			$statusCode < 200 ||
			$statusCode >= 300
		) {

			$this->logger->warning(
				'[Webhook] Request failed',
				[
					'statusCode' => $statusCode,
					'signUuid' => $signUuid,
					'app' => Application::APP_ID,
				]
			);

			return false;
		}

		$response = json_decode(
			$body,
			true,
		);

		if (!is_array($response)) {

			$this->logger->warning(
				'[Webhook] Invalid response',
				[
					'signUuid' => $signUuid,
					'app' => Application::APP_ID,
				]
			);

			return false;
		}

		if (
			($response['status'] ?? null) !== 'ok'
		) {

			$this->logger->warning(
				'[Webhook] Non-success response',
				[
					'status' => $response['status'] ?? null,
					'signUuid' => $signUuid,
					'app' => Application::APP_ID,
				]
			);

			return false;
		}

		$this->logger->info(
			'[Webhook] Signing token delivered',
			[
				'signUuid' => $signUuid,
				'app' => Application::APP_ID,
			]
		);

		return true;
	}


	private function getWebhookUrl(): string
	{
		$url = trim(
			$this->appConfig->getValueString(
				Application::APP_ID,
				self::CONFIG_KEY_WEBHOOK_URL,
				self::DEFAULT_WEBHOOK_URL,
			)
		);

		return $url !== ''
			? $url
			: self::DEFAULT_WEBHOOK_URL;
	}

	private function getWebhookSecret(): ?string
	{
		$secret = trim(
			$this->appConfig->getValueString(
				Application::APP_ID,
				self::CONFIG_KEY_WEBHOOK_SECRET,
				'',
			)
		);

		return $secret !== ''
			? $secret
			: null;
	}

	private function isWebhookOtpEnabled(): bool
	{
		$isEnabled = $this->appConfig->getValueBool(
			Application::APP_ID,
			self::CONFIG_KEY_WEBHOOK_ENABLED,
			false,
		);

		if (!$isEnabled) {

			$this->logger->debug(
				'[Webhook] Webhook OTP feature is disabled',
				[
					'app' => Application::APP_ID,
				]
			);
		}

		return $isEnabled;
	}
}
