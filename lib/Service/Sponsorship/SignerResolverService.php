<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Sponsorship;

use OCA\Libresign\Service\IdentifyMethodService;
use OCA\Libresign\Service\Sponsorship\DTO\SignerResolutionDTO;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Resolves the signer identity for settlement without relying on an
 * authenticated session.
 *
 * SignRequest identify-method rows are used instead of the file owner,
 * since a signing request may be completed by someone other than the owner.
 * Account identities take precedence over email identities.
 *
 * Email resolution is permitted only when exactly one account matches.
 * Ambiguous identities remain unresolved rather than risking settlement
 * against the wrong user's entitlement.
 */
class SignerResolverService {
	private const LOG_PREFIX = '[EntitlementSettlement][SignerResolverService]';

	public function __construct(
		private IdentifyMethodService $identifyMethodService,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	public function resolve(int $signRequestId): SignerResolutionDTO {
		$accountValue = null;
		$emailValue = null;

		$matrix = $this->identifyMethodService
			->getIdentifyMethodsFromSignRequestId($signRequestId);

		foreach ($matrix as $identifyMethods) {
			foreach ($identifyMethods as $identifyMethod) {
				$entity = $identifyMethod->getEntity();

				if ($entity->getIdentifierKey() === IdentifyMethodService::IDENTIFY_ACCOUNT) {
					$accountValue = $entity->getIdentifierValue();
				} elseif ($entity->getIdentifierKey() === IdentifyMethodService::IDENTIFY_EMAIL && $emailValue === null) {
					$emailValue = $entity->getIdentifierValue();
				}
			}
		}

		if ($accountValue !== null) {
			$user = $this->userManager->get($accountValue);
			if ($user !== null) {
				$this->logger->debug(
					self::LOG_PREFIX . ' Signer resolved via ACCOUNT identify method.',
					[
						'signRequestId' => $signRequestId,
						'signerUserId' => $user->getUID(),
						'outcome' => 'resolved',
					],
				);

				return SignerResolutionDTO::resolved($user->getUID());
			}

			// Avoid logging the UID because it directly identifies the account.
			$this->logger->warning(
				self::LOG_PREFIX . ' Account identify method points at an account that no longer exists.',
				[
					'signRequestId' => $signRequestId,
					'outcome' => 'account_not_found',
				],
			);

			return SignerResolutionDTO::unresolved('account_not_found');
		}

		if ($emailValue !== null) {
			$matches = $this->userManager->getByEmail($emailValue);

			if (count($matches) === 1) {
				$this->logger->debug(
					self::LOG_PREFIX . ' Signer resolved via EMAIL identify method.',
					[
						'signRequestId' => $signRequestId,
						'signerUserId' => current($matches)->getUID(),
						'outcome' => 'resolved',
					],
				);

				return SignerResolutionDTO::resolved(current($matches)->getUID());
			}

			$outcome = $matches === [] ? 'email_unprovisioned' : 'email_ambiguous';

			$this->logger->warning(
				self::LOG_PREFIX . ' Email identify method did not resolve to exactly one account; there is no session here to break the tie.',
				[
					'signRequestId' => $signRequestId,
					'matchCount' => count($matches),
					'outcome' => $outcome,
				],
			);

			return SignerResolutionDTO::unresolved($outcome);
		}

		$this->logger->warning(
			self::LOG_PREFIX . ' Sign request has no account or email identify method to resolve a signer from.',
			[
				'signRequestId' => $signRequestId,
				'outcome' => 'no_identity',
			],
		);

		return SignerResolutionDTO::unresolved('no_identity');
	}
}
