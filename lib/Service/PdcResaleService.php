<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\ExtendedAccountMapper;
use OCA\Libresign\Db\Payment;
use OCA\Libresign\Db\PaymentMapper;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\PaymentStatus;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ISession;

/**
 * GoPaperless resells SecurySign PDCs (FIP-025). A SecurySign user buys their
 * certificate here, through the certificate checkout (CERTIFICATE_ACCESS,
 * PaymentPurpose::CERTIFICATE_PURCHASE), at GoPaperless's own price. Before
 * MIMI runs the identity checks, the purchase is granted to them on Signa's
 * ledger from GoPaperless's prepaid stock. MIMI takes that grant as payment, so
 * SecurySign never sees what GoPaperless charged.
 *
 * Off unless `pdc_resale_enabled` is set. Until then MIMI takes the payment.
 */
class PdcResaleService {
	/** User value: the idempotency key of the last purchase granted on Signa. */
	public const GRANTED = 'securysign_pdc_granted';
	private const HOLD_OFF_KEY = 'libresign.securysign.pdc_hold_off_until';
	private const HOLD_OFF_SECONDS = 60;

	public function __construct(
		private SecurySignService $signa,
		private ExtendedAccountMapper $accounts,
		private PaymentMapper $payments,
		private IAppConfig $config,
		private IConfig $userConfig,
		private ISession $session,
	) {
	}

	/** Whether the signed-in SecurySign user buys their PDC in GoPaperless. */
	public function applies(): bool {
		return $this->config->getValueBool(Application::APP_ID, 'pdc_resale_enabled', false)
			&& $this->signa->applies();
	}

	/** Whether $uid has bought a certificate that is still current. */
	public function paid(string $uid): bool {
		return $this->accounts->findByUserId($uid)?->isCertificateValid() === true;
	}

	/**
	 * What the certificate checkout gates on for a reseller user. A certificate
	 * on SecurySign settles it, whatever GoPaperless's own record says, and an
	 * outage never paywalls anyone.
	 */
	public function certificateValid(string $uid): bool {
		try {
			if ($this->signa->isReady()) {
				return true;
			}
		} catch (\Throwable) {
			return true;
		}
		return $this->paid($uid);
	}

	/**
	 * Grants the PDC behind $uid's latest certificate purchase on Signa, once.
	 * Runs in the user's session, because the grant is made with their own token:
	 * a payment settles server to server, where there is none.
	 *
	 * A failure holds the hand-off to MIMI for a minute, so the gate renders the
	 * page instead of sending the user back here in a loop.
	 */
	public function grant(string $uid): void {
		$payment = $this->latestCertificatePurchase($uid);
		if ($payment === null) {
			throw new \RuntimeException('There is no paid certificate to set up on SecurySign.', 409);
		}
		$key = 'gp-pdc-' . $payment->getPaymentAttemptId();
		if ($this->userConfig->getUserValue($uid, Application::APP_ID, self::GRANTED) === $key) {
			return;
		}
		try {
			$this->signa->grantPdc($key);
		} catch (\Throwable $e) {
			$this->session->set(self::HOLD_OFF_KEY, time() + self::HOLD_OFF_SECONDS);
			throw $e;
		}
		$this->userConfig->setUserValue($uid, Application::APP_ID, self::GRANTED, $key);
	}

	public function heldOff(): bool {
		$until = $this->session->get(self::HOLD_OFF_KEY);
		return is_int($until) && $until > time();
	}

	private function latestCertificatePurchase(string $uid): ?Payment {
		// ponytail: only the newest 30 payments. 30 other payments between buying the
		// certificate and setting it up would make grant() refuse; query by purpose then.
		foreach ($this->payments->findRecentByUserId($uid) as $payment) {
			if ($payment->getPaymentPurpose() === PaymentPurpose::CERTIFICATE_PURCHASE
				&& $payment->getPaymentStatus() === PaymentStatus::PAID) {
				return $payment;
			}
		}
		return null;
	}
}
