<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Enum\PaymentMethod;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\ProductCode;
use OCA\Libresign\Service\Entitlement\EntitlementService;
use OCA\Libresign\Service\Payment\DTO\StartPaymentDTO;
use OCA\Libresign\Service\Product\ProductService;
use OCP\IAppConfig;
use OCP\IUser;

/**
 * Signing credits bought on a page reached by a link, for an account that
 * isn't signed in: the Seal & Deliver page (the moderator's) and the WhatsApp
 * signing card (the signer's). The same PaymentService as the app's payment
 * step, so a settled payment grants credits the usual way. The link stands in
 * for the session, so callers decide who pays, never the browser.
 */
class LinkPaymentService {
	public function __construct(
		private PaymentService $payments,
		private ProductService $products,
		private EntitlementService $entitlements,
		private IAppConfig $appConfig,
	) {
	}

	/** Credits apply only with sponsorship on, as on the signing page. */
	public function on(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'sponsorship_enabled', false);
	}

	/** One signature, in whole shillings: oc_gopaperless_products keeps minor units. */
	public function price(): int {
		return intdiv($this->products->getDefaultByCode(ProductCode::SIGN_DOCUMENT->value)->getAmount(), 100);
	}

	public function credits(IUser $user): int {
		return (int)$this->entitlements->getAvailableCredits($user->getUID(), ProductCode::SIGN_DOCUMENT->value)['remainingUses'];
	}

	/**
	 * Start buying $quantity signatures for $payer. M-Pesa's prompt goes to
	 * $phone; a card goes through DPO's page and comes back to $returnUrl.
	 *
	 * @return array{reference: string, flow: string, redirectUrl: ?string}
	 */
	public function start(IUser $payer, int $quantity, string $method, string $phone, string $returnUrl): array {
		$card = $method === 'card';
		$result = $this->payments->startPayment(new StartPaymentDTO(
			userEmail: (string)$payer->getEMailAddress(),
			signUuid: null,
			signRequestId: null,
			purpose: PaymentPurpose::CREDIT_PURCHASE,
			redirectUrl: $card ? $returnUrl : null,
			userId: $payer->getUID(),
			provider: null,
			productCode: ProductCode::SIGN_DOCUMENT->value,
			paymentMethod: $card ? PaymentMethod::CARD : PaymentMethod::MOBILE,
			quantity: max(1, $quantity),
			phoneNumber: $card ? null : $phone,
			returnUrl: $card ? $returnUrl : null,
		))->toArray();
		// DPO mobile money (Airtel) waits for a charge call that names the network.
		if (!$card && ($result['flow'] ?? '') === 'mobile_direct' && !($result['alreadyCharged'] ?? false)
			&& isset($result['selected']['mno'], $result['selected']['country'])) {
			$this->payments->chargeMobile($result['reference'], $phone, $result['selected']['mno'], $result['selected']['country']);
		}
		return ['reference' => (string)$result['reference'], 'flow' => (string)$result['flow'], 'redirectUrl' => $result['redirectUrl'] ?? null];
	}

	/** SUCCESS, PENDING, FAILED, CANCELLED, EXPIRED or INITIATION_FAILED. Throws when $reference isn't $payer's. */
	public function status(IUser $payer, string $reference): string {
		$this->payments->assertPaymentOwnership($reference, $payer->getUID());
		return $this->payments->mapPaymentStatus($this->payments->getPaymentStatus($reference));
	}
}
