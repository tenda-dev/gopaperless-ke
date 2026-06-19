<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020-2024 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\IdentifyMethod\SignatureMethod;

use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\MailService;
use OCA\Libresign\Service\SMS\SMSService;
use OCA\Libresign\Service\SMS\Webhook\WebhookService;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use OCP\Server;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class TokenService {
	public const TOKEN_LENGTH = 6;

	public function __construct(
		private ISecureRandom $secureRandom,
		private IHasher $hasher,
		private MailService $mail,
		private IL10N $l10n,
		private SMSService $smsService,
		private LoggerInterface $logger,
		private IAccountManager $accountManager,
		private WebhookService $webhookService,
	) {
	}

	public function sendCodeByGateway(string $identifier, string $gatewayName): string {
		// $gateway = $this->getGateway($gatewayName);

		$code = $this->secureRandom->generate(self::TOKEN_LENGTH, ISecureRandom::CHAR_DIGITS);
		// $gateway->send($identifier, $this->l10n->t('%s is your LibreSign verification code.', $code));
		return $this->hasher->hash($code);
	}

	/**
	 * @throws OCSForbiddenException
	 * @return \OCA\TwoFactorGateway\Provider\Gateway\IGateway
	 */
	private function getGateway(string $gatewayName) {
		// try {
		// 	$factory = Server::get(\OCA\TwoFactorGateway\Provider\Gateway\Factory::class);
		// } catch (NotFoundExceptionInterface) {
		// 	throw new LibresignException('App Two-Factor Gateway is not installed.');

		// }
		// $gateway = $factory->get($gatewayName);
		// if (!$gateway->isComplete()) {
		// 	throw new OCSForbiddenException($this->l10n->t('Gateway %s not configured on Two-Factor Gateway.', $gatewayName));
		// }
		// return $gateway;
	}

	public function sendCodeByEmail(string $email, string $displayName, ?string $uuid): string {
		$code = $this->secureRandom->generate(self::TOKEN_LENGTH, ISecureRandom::CHAR_DIGITS);
		$this->mail->sendCodeToSign(
			email: $email,
			name: $displayName,
			code: $code
		);

		// Attempt to send OTP via Webhhook (BEST EFFORT)
		$this->webhookService->sendCodeToSign(
			signerEmail: $email,
			signUuid: $uuid,
			code: $code
		);

		// Attempt to send SMS OTP if enabled and user has a phone number (BEST EFFORT)
		$phoneNumber = $this->getCurrentUserPhoneNumber();
		if ($phoneNumber !== null) {
			$this->smsService->sendCodeToSign(
				$phoneNumber,
				$code
			);
		}

		return $this->hasher->hash($code);
	}


	private function getCurrentUserPhoneNumber(): ?string
	{
		$user = null;

		try {
			$user = Server::get(IUserSession::class)
				->getUser();

			if ($user === null) {
				return null;
			}

			return $this->accountManager
				->getAccount($user)
				->getProperty(IAccountManager::PROPERTY_PHONE)
				->getValue();
		} catch (PropertyDoesNotExistException $e) {

			$this->logger->debug(
				'[TokenService] User does not have a phone number configured',
				[
					'userId' => $user?->getUID(),
				]
			);

			return null;
		} catch (Throwable $e) {

			$this->logger->warning(
				'[TokenService] Failed to retrieve current user phone number',
				[
					'userId' => $user?->getUID(),
					'error' => $e->getMessage(),
				]
			);

			return null;
		}
	}
}
