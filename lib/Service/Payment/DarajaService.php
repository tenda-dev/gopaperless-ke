<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service responsible for interacting with Safaricom Daraja API (M-Pesa STK Push).
 *
 * Responsibilities:
 * - Generate OAuth access token
 * - Initiate STK push request
 * - Build callback URL
 * - Format phone numbers for Daraja
 */
class DarajaService {

	private IClientService $clientService;
	private LoggerInterface $logger;
	private IRequest $request;
	private IAppConfig $appConfig;
	private IURLGenerator $urlGenerator;

	/**
	 * Daraja API configuration
	 * TODO: Should be moved to config/env
	 */

	public function __construct(
		IClientService $clientService,
		LoggerInterface $logger,
		IRequest $request,
		IAppConfig $appConfig,
		IURLGenerator $urlGenerator,
	) {
		$this->clientService = $clientService;
		$this->logger = $logger;
		$this->request = $request;
		$this->appConfig = $appConfig;
		$this->urlGenerator = $urlGenerator;
	}

	/**
	 * Initiate STK Push
	 *
	 * Expected payload:
	 * - phone (E.164 format, e.g. +2547...)
	 * - amount
	 * - signUuid (used as AccountReference)
	 *
	 * Returns:
	 * - reference (CheckoutRequestID)
	 * @throws \Exception
	 */
	public function initiatePayment(array $payload): array {

		$config = $this->getConfig();

		$shortCode = $config['shortCode'];
		$passKey = $config['passKey'];
		$baseUrl = $config['baseUrl'];

		$amount = $payload['amount'];

		$this->validateAmount($amount);

		/**
		 * DARAJA AMOUNT CONSTRAINT
		 *
		 * Daraja (M-Pesa) requires amounts as INTEGER values in major units (KES).
		 *
		 * Example:
		 * - 80.00 → 80 ✅
		 * - 75.50 → INVALID ❌
		 *
		 * IMPORTANT:
		 * - No decimals allowed
		 * - Amount MUST be validated before sending request
		 *
		 * RULE:
		 * - DO NOT round or mutate the amount
		 * - FAIL FAST if amount is not a whole number
		 *
		 * WHY:
		 * Silent mutation may result in incorrect charges (financial integrity risk)
		 */
		$amountInt = (int)$amount;

		$token = $this->getAccessToken();
		$client = $this->clientService->newClient();

		// Timestamp required by Daraja
		$timestamp = date('YmdHis');

		// Password = base64(shortCode + passKey + timestamp)
		$password = base64_encode(
			$shortCode . $passKey . $timestamp
		);

		// Build callback URL
		$callbackUrl = $this->getCallbackUrl();

		$this->logger->debug('Daraja callback URL generated', [
			'callbackUrl' => $callbackUrl
		]);

		// Convert +254... → 254...
		$formattedPhone = $this->formatPhone($payload['phone']);

		$requestBody = [
			'BusinessShortCode' => $shortCode,
			'Password' => $password,
			'Timestamp' => $timestamp,
			'TransactionType' => 'CustomerPayBillOnline',
			'Amount' => $amountInt,
			'PartyA' => $formattedPhone,
			'PartyB' => $shortCode,
			'PhoneNumber' => $formattedPhone,
			'CallBackURL' => $callbackUrl,

			/**
			 * AccountReference:
			 * - Visible to user
			 * - NOT used for reconciliation
			 */
			'AccountReference' => $payload['internalReference'],

			'TransactionDesc' => 'GoPaperless Signature Payment',
		];

		$this->logger->debug('Sending Daraja STK Push', [
			'phone' => $formattedPhone,
			'amount' => $payload['amount'],
			'internal_reference' => $payload['internalReference']
		]);

		$response = $client->post(
			$baseUrl . '/mpesa/stkpush/v1/processrequest',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type' => 'application/json',
				],
				'body' => json_encode($requestBody),
				'timeout' => 30
			]
		);

		$data = json_decode($response->getBody(), true);

		if (!is_array($data)) {
			throw new RuntimeException('Invalid JSON response from Daraja');
		}

		$this->logger->info('Daraja STK response', [
			'response' => $data
		]);

		/**
		 * Daraja success response:
		 * ResponseCode === "0"
		 */
		if (!isset($data['ResponseCode']) || $data['ResponseCode'] !== '0') {

			$this->logger->error('Daraja STK push failed', [
				'response' => $data
			]);

			throw new RuntimeException(
				'Daraja STK push failed: ' . ($data['errorMessage'] ?? 'Unknown error')
			);
		}

		/**
		 * IMPORTANT:
		 * CheckoutRequestID is our external reference
		 * Used later in callback to map payment
		 */
		return [
			'reference' => $data['CheckoutRequestID'],
			'raw' => $data,
			'message' => $data['ResponseDescription'] ?? 'STK push initiated',
		];
	}

	public function test(): array {
		return [
			'test' => true,
			'provider' => 'daraja'
		];
	}

	public function queryStkStatus(string $checkoutRequestId): array {
		$config = $this->getConfig();

		// Validate config early
		if (
			empty($config['shortCode'])
			|| empty($config['passKey'])
			|| empty($config['baseUrl'])
		) {
			$this->logger->error('Daraja config missing for query', $config);
			throw new \RuntimeException('Daraja configuration is incomplete.');
		}

		$timestamp = date('YmdHis');

		$password = base64_encode(
			$config['shortCode']
			. $config['passKey']
			. $timestamp
		);

		$token = $this->getAccessToken();
		$client = $this->clientService->newClient();
		$baseUrl = $config['baseUrl'];

		$url = rtrim($baseUrl, '/') . '/mpesa/stkpushquery/v1/query';

		try {
			$response = $client->post($url, [
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type' => 'application/json',
				],
				'json' => [
					'BusinessShortCode' => $config['shortCode'],
					'Password' => $password,
					'Timestamp' => $timestamp,
					'CheckoutRequestID' => $checkoutRequestId,
				],
			]);

			$data = json_decode($response->getBody(), true);

			if (!is_array($data)) {
				throw new RuntimeException('Invalid JSON response from Daraja');
			}

			$this->logger->info('Daraja query response', [
				'checkoutRequestId' => $checkoutRequestId,
				'response' => $data,
			]);

			return $this->normalizeSTKQueryResponse($data);

		} catch (\Throwable $e) {
			$this->logger->error('Daraja query failed', [
				'checkoutRequestId' => $checkoutRequestId,
				'error' => $e->getMessage(),
			]);

			throw $e;
		}
	}

	/**
	 * Generate OAuth Access Token
	 */
	private function getAccessToken(): string {

		$config = $this->getConfig();

		$consumerKey = $config['consumerKey'];
		$consumerSecret = $config['consumerSecret'];
		$baseUrl = $config['baseUrl'];

		$client = $this->clientService->newClient();

		$credentials = base64_encode(
			$consumerKey . ':' . $consumerSecret
		);

		$response = $client->get(
			$baseUrl . '/oauth/v1/generate?grant_type=client_credentials',
			[
				'headers' => [
					'Authorization' => 'Basic ' . $credentials,
				],
				'timeout' => 30
			]
		);

		$data = json_decode($response->getBody(), true);


		if (!is_array($data)) {
			throw new RuntimeException('Invalid JSON response from Daraja');
		}

		if (!isset($data['access_token'])) {

			$this->logger->error('Daraja token generation failed', [
				'response' => $data
			]);

			throw new RuntimeException('Failed to get Daraja access token');
		}

		return $data['access_token'];
	}

	/**
	 * @param float $amount
	 * @return void
	 */
	private function validateAmount(float $amount): void {
		if (floor($amount) !== $amount) {
			throw new RuntimeException(
				'Daraja requires whole number amounts. Received: ' . $amount
			);
		}

		if ($amount <= 0) {
			throw new RuntimeException('Amount must be greater than zero');
		}
	}

	/**
	 * Build callback URL dynamically
	 *
	 * Priority:
	 * 1. Manual override (ngrok/testing)
	 * 2. Config-driven base URL (admin UI)
	 * 3. Proxy headers (nginx/ngrok)
	 * 4. Nextcloud base URL
	 * 5. Request host (last resort)
	 */
	private function getCallbackUrl(): string {

		$callbackPath = '/ocs/v2.php/apps/libresign/api/v1/payment/webhook/daraja';

		$config = $this->getConfig();

		/**
		 * =========================================================
		 * 1. Manual override (HIGHEST PRIORITY - dev/testing only)
		 * =========================================================
		 *
		 * Used for:
		 * - local debugging with external callbacks
		 *
		 * MUST be empty in production.
		 */
		$manualOverrideBaseUrl = ''; // e.g. https://xxxxx.ngrok-free.app

		if (!empty($manualOverrideBaseUrl)) {
			return rtrim($manualOverrideBaseUrl, '/') . $callbackPath;
		}

		/**
		 * =========================================================
		 * 2. Config-driven base URL (production-ready)
		 * =========================================================
		 *
		 * Set via admin UI:
		 * gopaperless_callback_base_url
		 *
		 */
		$configBaseUrl = trim($config['callbackBaseUrl'] ?? '');

		if (!empty($configBaseUrl)) {
			return rtrim($configBaseUrl, '/') . $callbackPath;
		}

		/**
		 * =========================================================
		 * 3. Proxy-aware detection
		 * =========================================================
		 *
		 * Handles:
		 * - nginx reverse proxy
		 * - cloudflare
		 * - ngrok (if headers are forwarded)
		 */
		$forwardedProto = $this->request->getHeader('X-Forwarded-Proto');
		$forwardedHost = $this->request->getHeader('X-Forwarded-Host');

		if (!empty($forwardedProto) && !empty($forwardedHost)) {
			return $forwardedProto . '://' . $forwardedHost . $callbackPath;
		}

		/**
		 * =========================================================
		 * 4. Nextcloud base URL fallback
		 * =========================================================
		 *
		 * Uses the configured Nextcloud base URL (overwritehost / trusted_domains).
		 * Reliable behind reverse proxies where the request object cannot detect a host.
		 */
		$nextcloudBaseUrl = $this->urlGenerator->getBaseUrl();
		if (!empty($nextcloudBaseUrl)) {
			return rtrim($nextcloudBaseUrl, '/') . $callbackPath;
		}

		/**
		 * =========================================================
		 * 5. Request-host fallback (last resort)
		 * =========================================================
		 */
		$host = $this->request->getServerHost();

		// Basic scheme detection
		$isHttps = (
			$this->request->getHeader('HTTPS') === 'on'
			|| $this->request->getHeader('X-Forwarded-Proto') === 'https'
		);

		$scheme = $isHttps ? 'https' : 'http';

		return $scheme . '://' . $host . $callbackPath;
	}

	/**
	 * Convert E.164 phone number to Daraja format
	 *
	 * +254712345678 → 254712345678
	 */
	private function formatPhone(string $phone): string {

		if (!str_starts_with($phone, '+')) {
			throw new RuntimeException('Phone number must be in E.164 format (+254...)');
		}

		$formatted = substr($phone, 1);

		// Ensure Kenyan number
		if (!str_starts_with($formatted, '254')) {
			throw new RuntimeException('Only Kenyan numbers are supported for M-Pesa');
		}

		return $formatted;
	}

//		private function getConfig(): array {
//			return [
//				'baseUrl' => $this->appConfig->getValueString(
//					Application::APP_ID,
//					'daraja_base_url'
//				),
//				'consumerKey' => $this->appConfig->getValueString(
//					Application::APP_ID,
//					'daraja_consumer_key'
//				),
//				'consumerSecret' => $this->appConfig->getValueString(
//					Application::APP_ID,
//					'daraja_consumer_secret'
//				),
//				'passKey' => $this->appConfig->getValueString(
//					Application::APP_ID,
//					'daraja_pass_key'
//				),
//				'shortCode' => $this->appConfig->getValueString(
//					Application::APP_ID,
//					'daraja_shortcode'
//				),
//				'goPaperlessCallbackUrl' => $this->appConfig->getValueString(
//					Application::APP_ID,
//					'daraja_gopaperless_callback_base_url'
//				),
//			];
//		}

	// private function getConfig(): array {
	// 	return $this->getTestConfig();
	// }

	/**
	 * Map a Safaricom Daraja result code to a safe, user-facing reason key.
	 *
	 * Raw provider descriptions are kept out of the API surface to avoid
	 * leaking internal system state or subscriber details.
	 */
	public static function mapResultCodeToReason(?int $code): string
	{
		return match ($code) {
			0 => 'success',
			1 => 'insufficient_balance',
			17, 26, 1025 => 'provider_error',
			1001 => 'transaction_in_progress',
			1019 => 'expired',
			1032 => 'cancelled',
			1037 => 'timeout',
			2001 => 'wrong_pin',
			default => 'generic_failure',
		};
	}

	private function normalizeSTKQueryResponse(array $data): array {
		$resultCode = $data['ResultCode'] ?? null;
		$resultDesc = $data['ResultDesc'] ?? '';
		$reason = self::mapResultCodeToReason(is_numeric($resultCode) ? (int)$resultCode : null);

		return match ($resultCode) {
			'0' => [
				'status' => 'SUCCESS',
				'raw' => $data,
				'description' => $resultDesc,
			],
			// user cancelled
			'1032' => [
				'status' => 'CANCELLED',
				'reason' => $reason,
				'raw' => $data,
				'description' => $resultDesc,
			],
			// terminal failures
			'1', '17', '26', '1001', '1019', '1025', '1036', '1037', '2001', '2006' => [
				'status' => 'FAILED',
				'reason' => $reason,
				'raw' => $data,
				'description' => $resultDesc,
			],

			default => [
				'status' => 'PENDING',
				'raw' => $data,
				'description' => $resultDesc,
			],
		};
	}

	private function getConfig(): array {
		$baseUrl = $this->appConfig->getValueString(Application::APP_ID, 'daraja_base_url', '');
		$consumerKey = $this->appConfig->getValueString(Application::APP_ID, 'daraja_consumer_key', '');
		$consumerSecret = $this->appConfig->getValueString(Application::APP_ID, 'daraja_consumer_secret', '');
		$passKey = $this->appConfig->getValueString(Application::APP_ID, 'daraja_pass_key', '');
		$shortCode = $this->appConfig->getValueString(Application::APP_ID, 'daraja_shortcode', '');

		$missing = [];
		if ($baseUrl === '') {
			$missing[] = 'daraja_base_url';
		}
		if ($consumerKey === '') {
			$missing[] = 'daraja_consumer_key';
		}
		if ($consumerSecret === '') {
			$missing[] = 'daraja_consumer_secret';
		}
		if ($passKey === '') {
			$missing[] = 'daraja_pass_key';
		}
		if ($shortCode === '') {
			$missing[] = 'daraja_shortcode';
		}

		if (!empty($missing)) {
			throw new \RuntimeException('Daraja payment configuration is incomplete: ' . implode(', ', $missing));
		}

		$callbackBaseUrl = $this->appConfig->getValueString(Application::APP_ID, 'gopaperless_callback_base_url', '');

		return [
			'baseUrl' => $baseUrl,
			'consumerKey' => $consumerKey,
			'consumerSecret' => $consumerSecret,
			'passKey' => $passKey,
			'shortCode' => $shortCode,
			'callbackBaseUrl' => $callbackBaseUrl,
		];
	}
}
