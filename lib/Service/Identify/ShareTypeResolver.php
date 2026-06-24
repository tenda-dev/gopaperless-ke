<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Identify;

use OCA\Libresign\Collaboration\Collaborators\AccountPhonePlugin;
use OCA\Libresign\Collaboration\Collaborators\ContactPhonePlugin;
use OCA\Libresign\Collaboration\Collaborators\EmailAccountPlugin;
use OCA\Libresign\Collaboration\Collaborators\ManualPhonePlugin;
use OCA\Libresign\Collaboration\Collaborators\SignerPlugin;
use OCA\Libresign\Service\IdentifyMethod\Account;
use OCA\Libresign\Service\IdentifyMethod\Email;
use OCP\IAppConfig;
use OCP\Share\IShare;

class ShareTypeResolver
{
	private const PHONE_METHODS = ['whatsapp', 'sms', 'telegram', 'signal', 'email'];

	public function __construct(
		private Email $identifyEmailMethod,
		private Account $identifyAccountMethod,
		private IAppConfig $appConfig,
	) {}

	public function resolve(string $method = ''): array
	{
		$normalizedMethod = strtolower(trim($method));

		$isAllMethods = $normalizedMethod === '' || $normalizedMethod === 'all';
		$isEmailMethod = $normalizedMethod === 'email';

		$includeAccount = $isAllMethods || $normalizedMethod === 'account';

		/**
		 * Product decision:
		 *
		 * In GoPaperless, email is treated as the primary identity mechanism.
		 * Requesters typically know the signer's email address, but they should
		 * not need to understand whether the signer already has an internal
		 * account or is an external signer.
		 *
		 * To provide a simpler UX and prevent duplicate external signers for
		 * existing users, email searches also include account searches.
		 *
		 * Existing accounts are returned as account signers while unknown email
		 * addresses continue to be returned as external email signers.
		 *
		 * If this behaviour changes in the future, ensure that searching by
		 * email continues to resolve existing users to account signers.
		 */
		$includeEmail = $isAllMethods || $isEmailMethod;

		$includePhone = $isAllMethods
			|| $isEmailMethod
			|| in_array($normalizedMethod, self::PHONE_METHODS, true);

		$shareTypes = [];
		$accountEnabled = false;
		$emailEnabled = false;

		if ($includeEmail) {
			$emailSettings = $this->identifyEmailMethod->getSettings();
			$emailEnabled = $emailSettings['enabled'];

			if ($emailEnabled) {
				$shareTypes[] = IShare::TYPE_EMAIL;
				// Resolve email addresses to existing internal accounts.
				$shareTypes[] = EmailAccountPlugin::TYPE_SIGNER_EMAIL_ACCOUNT;
			}

			// Include existing account users in email searches.
			$accountSettings = $this->identifyAccountMethod->getSettings();
			$accountEnabled = $accountSettings['enabled'];

			if ($accountEnabled) {
				$shareTypes[] = IShare::TYPE_USER;
			}
		}

		// Only add account share types explicitly if not already added
		// through the unified email search behaviour.
		if ($includeAccount && !$includeEmail) {
			$settings = $this->identifyAccountMethod->getSettings();
			$accountEnabled = $settings['enabled'];

			if ($accountEnabled) {
				$shareTypes[] = IShare::TYPE_USER;
			}
		}

		$shareTypes[] = SignerPlugin::TYPE_SIGNER;

		$phonePluginsEnabled = $includePhone && $this->isAnyPhoneMethodEnabled();
		$unifiedPhoneLookup = ($isAllMethods || $isEmailMethod) && $accountEnabled;

		// AccountPhonePlugin must run for unified/email phone lookups when Account
		// is enabled, even if no phone-specific identification factor is enabled.
		if ($phonePluginsEnabled || $unifiedPhoneLookup) {
			$shareTypes[] = AccountPhonePlugin::TYPE_SIGNER_ACCOUNT_PHONE;
		}

		if ($phonePluginsEnabled) {
			$shareTypes[] = ContactPhonePlugin::TYPE_SIGNER_CONTACT_PHONE;
			$shareTypes[] = ManualPhonePlugin::TYPE_SIGNER_MANUAL_PHONE;
		}

		return array_unique($shareTypes);
	}

	private function isAnyPhoneMethodEnabled(): bool {
		$methods = $this->appConfig->getValueArray('libresign', 'identify_methods', []);
		foreach ($methods as $method) {
			if ((bool)($method['enabled'] ?? false)
				&& in_array($method['name'] ?? '', self::PHONE_METHODS, true)) {
				return true;
			}
		}
		return false;
	}
}
