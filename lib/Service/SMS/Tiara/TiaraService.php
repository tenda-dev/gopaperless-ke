<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\SMS\Tiara;

use OCA\Libresign\AppInfo\Application;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

final class TiaraService {
	public function __construct(
		private LoggerInterface $logger,
		private IClientService $httpClientService,
		private TiaraConfigurationService $configurationService,
	) {
	}

	/**
	 * Sends an SMS using the Tiara SMS gateway.
	 *
	 * Responsibilities:
	 * - Retrieve Tiara configuration
	 * - Build the request payload
	 * - Execute the HTTP request
	 * - Validate the Tiara response
	 * - Log infrastructure concerns
	 */
	public function send(
		string $phone,
		string $message,
	): bool {
		$config = $this->configurationService->get();

		if ($config === null) {
			return false;
		}

		$payload = $this->buildPayload(
			$phone,
			$message,
			$config->senderId,
		);

		try {
			$client = $this->httpClientService->newClient();

			$this->logger->info(
				'[Tiara] Sending SMS',
				[
					'phone' => $phone,
					'refId' => $payload['refId'],
					'app' => Application::APP_ID,
				]
			);

			$response = $client->post(
				$config->apiUrl,
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $config->apiKey,
						'Accept' => 'application/json',
						'Content-Type' => 'application/json',
					],
					'body' => json_encode(
						$payload,
						JSON_THROW_ON_ERROR,
					),
					'timeout' => 10,
				]
			);

			return $this->handleResponse(
				$response->getStatusCode(),
				$response->getBody(),
			);
		} catch (Throwable $e) {

			$this->logger->error(
				'[Tiara] Failed to send SMS',
				[
					'phone' => $phone,
					'error' => $e->getMessage(),
					'exception' => $e,
					'app' => Application::APP_ID,
				]
			);

			return false;
		}
	}

	private function generateReferenceId(): string {
		return Uuid::uuid4()->toString();
	}

	/**
	 * Builds the Tiara SMS payload.
	 *
	 * @return array{
	 *     from: string,
	 *     to: string,
	 *     message: string,
	 *     refId: string
	 * }
	 */
	private function buildPayload(
		string $phone,
		string $message,
		string $senderId,
	): array {
		return [
			'from' => $senderId,
			'to' => $phone,
			'message' => $message,
			'refId' => $this->generateReferenceId(),
		];
	}

	/**
	 * Determines whether Tiara accepted the SMS request.
	 *
	 * Success criteria:
	 * - HTTP 200 response
	 * - status = SUCCESS
	 * - statusCode = 0
	 */
	private function handleResponse(
		int $statusCode,
		string $body,
	): bool {

		$this->logger->info(
			'[Tiara] SMS response received',
			[
				'statusCode' => $statusCode,
				'app' => Application::APP_ID,
			]
		);

		if ($statusCode !== 200) {

			$this->logger->error(
				'[Tiara] SMS request failed',
				[
					'statusCode' => $statusCode,
					'body' => $body,
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

			$this->logger->error(
				'[Tiara] Invalid response',
				[
					'body' => $body,
					'app' => Application::APP_ID,
				]
			);

			return false;
		}

		$success
			= ($response['status'] ?? null)
			=== 'SUCCESS'
			&& (string)(
				$response['statusCode']
				?? ''
			) === '0';

		if (!$success) {

			$this->logger->error(
				'[Tiara] SMS rejected',
				[
					'response' => $response,
					'app' => Application::APP_ID,
				]
			);

			return false;
		}

		$this->logger->info(
			'[Tiara] SMS accepted',
			[
				'msgId'
				=> $response['msgId']
					?? null,
				'to'
				=> $response['to']
					?? null,
				'cost'
				=> $response['cost']
					?? null,
				'app' => Application::APP_ID,
			]
		);

		return true;
	}
}
