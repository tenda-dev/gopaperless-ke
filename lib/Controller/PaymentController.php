<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Enum\PaymentMethod;
use OCA\Libresign\Enum\PaymentProvider;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Service\Payment\DTO\StartPaymentDTO;
use OCA\Libresign\Service\Payment\PaymentService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\Route;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\Exception;

use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class PaymentController extends AEnvironmentAwareController {

	private PaymentService $paymentService;
	protected LoggerInterface $logger;
	private SignRequestMapper $signRequestMapper;

	public function __construct(
		IRequest $request,
		PaymentService $paymentService,
		LoggerInterface $logger,
		protected IUserSession $userSession,
		SignRequestMapper $signRequestMapper,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->paymentService = $paymentService;
		$this->logger = $logger;
		$this->signRequestMapper = $signRequestMapper;
	}

	/**
	 * Start payment and return DPO token.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	#[ApiRoute(
		verb: 'POST',
		url: '/api/{apiVersion}/payment/start',
		requirements: ['apiVersion' => '(v1)']
	)]
	public function start(
		string $userEmail,
		?string $signUuid,
		?int $signRequestId,
		string $userId,
		?string $redirectUrl,
		string $productCode,
		?string $paymentAttemptId,
		?string $provider,
		?string $phoneNumber,
		?string $callbackUrl,
		?string $paymentMethod,
		?string $purpose,
		?int $quantity,
	): DataResponse {

		try {
			$user = $this->userSession->getUser();

			if (!$user) {
				return new DataResponse([
					'success' => false,
					'error' => 'Unauthorized',
				], Http::STATUS_UNAUTHORIZED);
			}

			$uid = $user->getUID();

			$this->logger->info('[PaymentStart]', [
				'userId' => $userId,
				'uid' => $uid,
			]);

			if (!$userId || $uid !== $userId) {
				return new DataResponse([
					'success' => false,
					'error' => 'Access Denied',
				], Http::STATUS_BAD_REQUEST);
			}

			$methodEnum = PaymentMethod::tryFrom($paymentMethod);

			if (!$methodEnum) {
				return new DataResponse([
					'success' => false,
					'error' => 'Please select valid payment method',
				], Http::STATUS_BAD_REQUEST);
			}

			$purposeEnum = PaymentPurpose::tryFrom(
				strtolower((string)$purpose)
			) ?? PaymentPurpose::SIGN_REQUEST;

			$providerEnum = $provider !== null
				? PaymentProvider::tryFrom($provider)
				: null;

			if (
				$purposeEnum === PaymentPurpose::SIGN_REQUEST
				&& ($signUuid === null || $signUuid === '')
				&& $signRequestId !== null
			) {
				try {
					$signRequest = $this->signRequestMapper->getById($signRequestId);
					$signUuid = $signRequest->getUuid();
				} catch (\Throwable $e) {
					$this->logger->warning('[PaymentStart] Could not resolve sign UUID from sign request ID', [
						'signRequestId' => $signRequestId,
						'error' => $e->getMessage(),
					]);
				}
			}

			$dto = new StartPaymentDTO(
				userEmail: $userEmail,
				signUuid: $signUuid,
				signRequestId: $signRequestId,
				redirectUrl: $redirectUrl,
				userId: $userId,
				provider: $providerEnum,
				productCode: $productCode,
				paymentMethod: $methodEnum,
				callbackUrl: $callbackUrl,
				paymentAttemptId: $paymentAttemptId,
				phoneNumber: $phoneNumber,
				purpose: $purposeEnum,
				quantity: $quantity ?? 1,
			);

			$result = $this->paymentService->startPayment($dto);

			return new DataResponse([
				'success' => true,
				'result' => $result->toArray()
			], Http::STATUS_OK);
		} catch (\Throwable $e) {

			$this->logger->error('Payment creation failed', [
				'exception' => $e
			]);

			return new DataResponse([
				'success' => false,
				'error' => 'Payment creation failed',
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Verify payment after redirect.
	 * @throws Exception
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	#[ApiRoute(
		verb: 'GET',
		url: '/api/{apiVersion}/payment/verify',
		requirements: ['apiVersion' => '(v1)']
	)]
	public function verify(string $providerReference): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['success' => false, 'error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$payment = $this->paymentService->assertPaymentOwnership($providerReference, $user->getUID());
		$status = $this->paymentService->verifyPayment($providerReference);

		$signRequestUuid = null;
		$signRequestId = $payment->getTransactionId();
		if ($signRequestId > 0) {
			try {
				$signRequest = $this->signRequestMapper->getById($signRequestId);
				$signRequestUuid = $signRequest->getUuid();
			} catch (\Throwable $e) {
				$this->logger->warning('[PaymentController] Could not resolve sign request UUID for payment', [
					'providerReference' => $providerReference,
					'signRequestId' => $signRequestId,
					'error' => $e->getMessage(),
				]);
			}
		}

		return new DataResponse([
			'status' => $this->paymentService->mapPaymentStatus($status),
			'reason' => $this->paymentService->getPaymentFailureReason($payment),
			'signRequestUuid' => $signRequestUuid,
		], Http::STATUS_OK);
	}

	/**
	 * Check if payment is complete.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	#[ApiRoute(
		verb: 'GET',
		url: '/api/{apiVersion}/payment/status',
		requirements: ['apiVersion' => '(v1)']
	)]
	public function status(string $providerReference): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['success' => false, 'error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$payment = $this->paymentService->assertPaymentOwnership($providerReference, $user->getUID());
		$status = $this->paymentService->getPaymentStatus($providerReference);

		return new DataResponse([
			'status' => $this->paymentService->mapPaymentStatus($status),
			'reason' => $this->paymentService->getPaymentFailureReason($payment),
		], Http::STATUS_OK);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[CORS]
	#[ApiRoute(
		verb: 'POST',
		url: '/api/{apiVersion}/payment/webhook/daraja',
		requirements: ['apiVersion' => '(v1)']
	)]
	public function darajaCallback(): DataResponse {

		$rawBody = file_get_contents('php://input');
		$data = json_decode($rawBody, true);

		if (!$data || !isset($data['Body']['stkCallback'])) {
			$this->logger->error('[Payment] Invalid Daraja callback payload', [
				'raw' => $rawBody
			]);

			return new DataResponse(['status' => 'invalid'], Http::STATUS_BAD_REQUEST);
		}

		$payload = $data['Body']['stkCallback'];

		$checkoutRequestId = $payload['CheckoutRequestID'] ?? null;

		if (!$checkoutRequestId) {
			$this->logger->error('[Payment] Missing CheckoutRequestID in callback', [
				'payload' => $payload
			]);

			return new DataResponse(['status' => 'Missing CheckoutRequestID'], HTTP::STATUS_BAD_REQUEST);
		}

		try {

			$this->paymentService->handleDarajaCallback($payload);
		} catch (\Throwable $e) {

			$this->logger->error('[Payment] Failed processing Daraja callback', [
				'error' => $e->getMessage(),
				'callback' => $payload
			]);

			return new DataResponse(['status' => 'error'], Http::STATUS_OK);
		}

		return new DataResponse(['status' => 'ok'], Http::STATUS_OK);
	}


	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[Route(
		type: Route::TYPE_FRONTPAGE,
		verb: 'GET',
		url: '/payment/webhook/dpo',
	)]
	public function dpoCallback(): DataResponse {
		// DPO contract: always respond with OK regardless of outcome
		$responseXml = '<?xml version="1.0" encoding="utf-8"?><API3G><Response>OK</Response></API3G>';
		$xmlHeaders = ['Content-Type' => 'application/xml'];

		// DPO sends params as GET query params, not a request body
		$payload = $this->request->getParams();

		$token = $payload['TransactionToken'] ?? null;

		if (!$token) {
			$this->logger->error('[DPO Callback] Missing TransactionToken');
			return new DataResponse($responseXml, Http::STATUS_OK, $xmlHeaders);
		}

		try {
			$this->paymentService->handleDpoCallback($payload);
		} catch (\Throwable $e) {
			$this->logger->error('[DPO Callback] Processing failed', [
				'token' => $token,
				'error' => $e->getMessage(),
			]);
		}

		return new DataResponse($responseXml, Http::STATUS_OK, $xmlHeaders);
	}

	/**
	 * @throws Exception
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	#[ApiRoute(
		verb: 'POST',
		url: '/api/{apiVersion}/payment/daraja/query',
		requirements: ['apiVersion' => '(v1)']
	)]
	public function queryDaraja(string $reference): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['success' => false, 'error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$payment = $this->paymentService->assertPaymentOwnership($reference, $user->getUID());
		$status = $this->paymentService->queryPayment($reference);

		return new DataResponse([
			'status' => $this->paymentService->mapPaymentStatus($status),
			'reason' => $this->paymentService->getPaymentFailureReason($payment),
		], Http::STATUS_OK);
	}

	/**
	 * Check if payment can be resumed.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[CORS]
	#[ApiRoute(
		verb: 'GET',
		url: '/api/{apiVersion}/payment/resume',
		requirements: ['apiVersion' => '(v1)']
	)]
	public function resume(
		?string $purpose,
		?int $signRequestId,
		?string $signUuid,
	): DataResponse {

		try {
			$user = $this->userSession->getUser();

			if (!$user) {
				return new DataResponse([
					'success' => false,
					'error' => 'Unauthorized',
				], Http::STATUS_UNAUTHORIZED);
			}

			$uid = $user->getUID();

			$purpose = $purpose ? strtolower($purpose) : null;

			$purposeEnum = PaymentPurpose::tryFrom(
				strtolower($purpose ?? '')
			) ?? PaymentPurpose::SIGN_REQUEST;

			$payment = $this->paymentService->resumePayment(
				purpose: $purposeEnum,
				signRequestId: $signRequestId,
				signUuid:$signUuid,
				userId: $uid,
			);

			return new DataResponse([
				'success' => true,
				'result' => $payment ? $payment->toArray() : null,
			], Http::STATUS_OK);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to resume payment', [
				'exception' => $e
			]);

			return new DataResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	#[ApiRoute(
		verb: 'POST',
		url: '/api/{apiVersion}/payment/charge-mobile',
		requirements: ['apiVersion' => '(v1)']
	)]
	public function chargeMobile(
		string $reference,
		string $phone,
		?string $mno = null,
		?string $country = null,
	): DataResponse {

		try {
			$user = $this->userSession->getUser();
			if (!$user) {
				return new DataResponse(['success' => false, 'error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
			}

			if (trim($reference) === '') {
				return new DataResponse([
					'success' => false,
					'error' => 'Missing payment reference',
				], Http::STATUS_BAD_REQUEST);
			}

			if (trim($phone) === '') {
				return new DataResponse([
					'success' => false,
					'error' => 'Missing phone number',
				], Http::STATUS_BAD_REQUEST);
			}

			if ($mno === null || trim($mno) === '') {
				return new DataResponse([
					'success' => false,
					'error' => 'Missing mobile provider',
				], Http::STATUS_BAD_REQUEST);
			}

			if ($country === null || trim($country) === '') {
				return new DataResponse([
					'success' => false,
					'error' => 'Missing mobile provider country',
				], Http::STATUS_BAD_REQUEST);
			}

			$payment = $this->paymentService->assertPaymentOwnership($reference, $user->getUID());

			$storedPhone = $payment->getPhoneE164Digits();
			if ($storedPhone !== null && $storedPhone !== $phone) {
				return new DataResponse([
					'success' => false,
					'error' => 'Phone number does not match the payment',
				], Http::STATUS_BAD_REQUEST);
			}

			$payment = $this->paymentService->chargeMobile(
				$reference,
				$phone,
				$mno,
				$country,
			);

			return new DataResponse([
				'success' => true,
				'result' => $payment
					? $payment->toArray()
					: null,
			], Http::STATUS_OK);
		} catch (\Throwable $e) {

			$this->logger->error(
				'Failed to charge mobile payment',
				[
					'reference' => $reference,
					'exception' => $e,
				]
			);

			return new DataResponse([
				'success' => false,
				'error' => $e->getMessage(),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[CORS]
	#[ApiRoute(
		verb: 'GET',
		url: '/api/{apiVersion}/payment/mobile-options',
		requirements: ['apiVersion' => '(v1)']
	)]
	public function getMobileOptions(string $reference, string $country): DataResponse {

		try {
			$user = $this->userSession->getUser();
			if (!$user) {
				return new DataResponse(['success' => false, 'error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
			}

			$this->paymentService->assertPaymentOwnership($reference, $user->getUID());

			$options = $this->paymentService->getMobileOptions($reference, $country);

			return new DataResponse([
				'success' => true,
				'options' => $options
			], Http::STATUS_OK);
		} catch (\Throwable $e) {

			$this->logger->error('Failed to fetch mobile options', [
				'error' => $e->getMessage(),
				'reference' => $reference
			]);

			return new DataResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
