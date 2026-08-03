<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\SMS;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\PhoneNumber\PhoneNumberService;
use OCA\Libresign\Service\SMS\Tiara\TiaraProvider;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

final class SMSService {
	private const CONFIG_KEY_SMS_OTP_ENABLED
		= 'sms_otp_enabled';

	public function __construct(
		private PhoneNumberService $phoneNumberService,
		private TiaraProvider $provider,
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * Sends an OTP required for document signing.
	 *
	 * Responsibilities:
	 * - Build the signing OTP message
	 * - Delegate delivery to send()
	 */
	public function sendCodeToSign(
		string $phone,
		string $code,
	): bool {

		if (!$this->isSmsOtpEnabled()) {
			return false;
		}

		return $this->send(
			phone: $phone,
			message: $this->l10n->t(
				'Use this code to sign the document: %s',
				[$code],
			),
		);
	}

	/**
	 * Sends an SMS message.
	 *
	 * Responsibilities:
	 * - Resolve and validate the phone number
	 * - Delegate SMS sending to the configured provider
	 * - Log orchestration failures
	 */
	public function send(
		string $phone,
		string $message,
	): bool {

		$resolvedPhone = null;

		try {
			$resolvedPhone = $this->phoneNumberService
				->resolve($phone);

			if (!$resolvedPhone->valid) {
				return false;
			}

			return $this->provider->send(
				$resolvedPhone,
				$message,
			);
		} catch (Throwable $e) {

			$this->logger->error(
				'[SMS] Failed to send SMS',
				[
					'phone'
					=> $resolvedPhone?->masked
						?? 'unknown',
					'error' => $e->getMessage(),
					'exception' => $e,
					'app' => Application::APP_ID,
				]
			);

			return false;
		}
	}

	private function isSmsOtpEnabled(): bool {
		$enabled = $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_KEY_SMS_OTP_ENABLED,
			'false',
		);

		$isEnabled = $enabled === 'true';

		if (!$isEnabled) {

			$this->logger->debug(
				'[SMS] SMS OTP feature is disabled',
				[
					'app' => Application::APP_ID,
				]
			);
		}

		return $isEnabled;
	}
}
