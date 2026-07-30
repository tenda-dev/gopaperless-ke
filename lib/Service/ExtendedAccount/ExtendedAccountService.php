<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\ExtendedAccount;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\ExtendedAccount;
use OCA\Libresign\Db\ExtendedAccountMapper;
use OCA\Libresign\Db\SignRequestMapper;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Coordinates the GoPaperless extended account lifecycle.
 *
 * Responsibilities:
 * - Retrieve extended account state.
 * - Lazily create account extensions for new users.
 * - Persist certificate state transitions.
 *
 * Business rules such as payment orchestration and certificate
 * validity calculation remain outside this service.
 */
final class ExtendedAccountService
{
	/**
	 * Default certificate validity for newly granted access.
	 *
	 * Used when no administrator override has been configured.
	 */
	private const DEFAULT_CERTIFICATE_VALIDITY_DAYS = 365;

	/**
	 * AppConfig key controlling certificate validity, in days.
	 *
	 * The resolved window is applied at WRITE time (grant/renewal) and
	 * the resulting `valid_until` is stored on the row. Changing this
	 * value is therefore NOT retroactive: existing rows keep the date
	 * they were written with, and only rows granted/renewed after the
	 * change use the new window. This is deliberate — retroactively
	 * recomputing a paid access window is a support nightmare.
	 *
	 * PROVISIONAL: `valid_until` is a business access window and is
	 * intentionally decoupled from the certificate's real X.509 crypto
	 * expiry, which the (unresolved) signing provider controls and which
	 * may not be 365 days. Once the provider question is settled these
	 * two clocks must be reconciled — shown separately with an
	 * explanation, or collapsed into one.
	 */
	private const CONFIG_KEY_CERTIFICATE_VALIDITY_DAYS = 'certificate_validity_days';

	/**
	 * AppConfig key for the certificate gate kill-switch.
	 *
	 * Positive polarity by design (avoids a double-negative that would
	 * eventually cause a production incident):
	 * - false (default) → gate INERT: every account is treated as
	 *   certificate-valid, nobody is paywalled.
	 * - true → real DB gating: must-pay accounts are gated and see the
	 *   paywall.
	 */
	private const CONFIG_KEY_CERTIFICATE_GATE_ENABLED = 'certificate_gate_enabled';

	public function __construct(
		private ExtendedAccountMapper $mapper,
		private SignRequestMapper $signRequestMapper,
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
	) {}

	/**
	 * Creates the extended account for a newly created user.
	 *
	 * The operation is idempotent to support retryable account creation
	 * workflows. Existing (grandfathered) users intentionally do not
	 * receive rows through lookup operations; this method is reserved
	 * for provisioning brand-new accounts.
	 */
	public function create(
		string $userId,
	): ExtendedAccount {

		if ($userId === '') {
			throw new \InvalidArgumentException('userId is required');
		}

		$existing = $this->mapper->findByUserId($userId);

		if ($existing !== null) {
			return $existing;
		}

		$account = $this->newAccount($userId);

		return $this->persist(
			account: $account,
			message: '[EXTENDED ACCOUNT] Created account extension.',
		);
	}

	/**
	 * Creates an extended account for a legacy signing user.
	 *
	 * Legacy users have already demonstrated certificate ownership by
	 * successfully signing documents before the extended account model
	 * was introduced. They are therefore granted certificate access
	 * without recording a payment.
	 *
	 * The validity period is determined from the current application
	 * configuration and only applies at migration time.
	 */
	private function createLegacyAccount(
		string $userId,
	): ExtendedAccount {

		$account = $this->newAccount($userId);

		$validUntil = $this->now($this->calculateCertificateValidity());

		$account->grantCertificateAccess($validUntil);

		return $this->persist(
			$account,
			'[EXTENDED ACCOUNT] Created legacy account extension.',
		);
	}

	/**
	 * Retrieve the extended account for a user.
	 *
	 * Returns null for existing users that do not yet have
	 * an extension row.
	 */
	public function getByUserId(
		string $userId,
	): ?ExtendedAccount {
		return $this->mapper->findByUserId($userId);
	}

	/**
	 * Retrieve the extended account for a user, creating it if necessary.
	 *
	 * This method provides the backwards compatibility bridge for
	 * grandfathered users that predate the extended account model.
	 * Once created, subsequent lookups return the persisted account.
	 */
	public function getOrCreate(
		string $userId,
		?string $email
	): ExtendedAccount {

		$account = $this->mapper->findByUserId($userId);

		if ($account !== null) {
			return $account;
		}

		if ($this->signRequestMapper->hasSignedDocuments($userId, $email)) {
			return $this->createLegacyAccount($userId);
		}

		return $this->create($userId);
	}

	/**
	 * Persist changes to an extended account.
	 */
	public function save(
		ExtendedAccount $account,
	): ExtendedAccount {

		return $this->mapper->update($account);
	}

	/**
	 * Grants or extends certificate access after a successful payment.
	 *
	 * Invoked from the payment finalise branch for
	 * PaymentPurpose::CERTIFICATE_PURCHASE. Idempotency is guaranteed by
	 * the caller (PaymentFinalizerService refuses to re-finalise an
	 * already-PAID payment), so this method applies exactly once.
	 *
	 * Renewal stacks: a still-valid certificate is extended from its
	 * current expiry, an expired or absent one from now — so an early
	 * renewal never burns remaining time.
	 */
	public function renewCertificate(
		string $userId,
		?string $email = null,
		?\DateTimeInterface $paidAt = null,
	): ExtendedAccount {

		$account = $this->getOrCreate($userId, $email);

		$validUntil = $this->addValidityWindow(
			$this->renewalBase($account),
		);

		$account->renewCertificate(
			$this->now($validUntil),
			$this->now($paidAt),
		);

		return $this->save($account);
	}

	/**
	 * Whether certificate gating is currently enforced.
	 *
	 * Defaults to false (feature inert). While disabled, the gate
	 * override is active and no account is paywalled regardless of DB
	 * state; flipping it true activates real DB gating.
	 */
	public function isGateEnabled(): bool
	{
		return $this->appConfig->getValueBool(
			Application::APP_ID,
			self::CONFIG_KEY_CERTIFICATE_GATE_ENABLED,
			false,
		);
	}

	/**
	 * Effective certificate validity for an account.
	 *
	 * This is THE single server-side authority the FE consumes as
	 * `isCertificateValid` on /account/me. It layers the admin
	 * kill-switch on top of the account's own DB-derived state:
	 * - gate disabled (default) → always valid, so nobody is paywalled;
	 * - gate enabled → the account's real certificate validity applies.
	 *
	 * Because gating is resolved here (not on the client), a flipped
	 * flag cannot be bypassed by a crafted request. A mid-session flip
	 * is reflected on the next /account/me hydration — the FE's
	 * userContext.refresh() is that seam.
	 */
	public function isCertificateValidForAccount(ExtendedAccount $account): bool
	{
		if (!$this->isGateEnabled()) {
			return true;
		}

		return $account->isCertificateValid();
	}

	/**
	 * Retrieve all accounts matching their certificate state.
	 *
	 * @return ExtendedAccount[]
	 */
	public function getAccountsWithPaidCertificates(
		bool $paid,
	): array {

		return $this->mapper->findByPaidCertificate($paid);
	}

	private function newAccount(
		string $userId,
	): ExtendedAccount {

		$account = new ExtendedAccount();
		$account->setUserId($userId);

		return $account;
	}

	private function persist(
		ExtendedAccount $account,
		string $message,
		array $context = [],
	): ExtendedAccount {

		try {
			$this->mapper->insert($account);
		} catch (\OCP\DB\Exception $e) {
			if (
				$e->getReason()
				=== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION
			) {
				$existing = $this->mapper->findByUserId(
					$account->getUserId(),
				);

				if ($existing !== null) {
					$this->logger->debug(
						'[EXTENDED ACCOUNT] Concurrent account creation detected.',
						[
							'userId' => $account->getUserId(),
						],
					);
					return $existing;
				}
			}

			throw $e;
		}

		if ($account->getValidUntil() !== null) {
			$context['validUntil'] = $account->getValidUntil();
		}

		$this->logger->info(
			$message,
			$context,
		);

		return $account;
	}

	/**
	 * Calculates the certificate validity window.
	 *
	 * Uses the administrator-configured validity period when available,
	 * otherwise falls back to the default of one year.
	 */
	private function calculateCertificateValidity(): \DateTimeImmutable
	{
		return $this->addValidityWindow($this->nowImmutable());
	}

	/**
	 * Adds the administrator-configured validity period to a base date.
	 *
	 * Falls back to the default one-year window when unconfigured.
	 */
	private function addValidityWindow(
		\DateTimeImmutable $base,
	): \DateTimeImmutable {

		$validityDays = $this->appConfig->getValueInt(
			Application::APP_ID,
			self::CONFIG_KEY_CERTIFICATE_VALIDITY_DAYS,
			self::DEFAULT_CERTIFICATE_VALIDITY_DAYS,
		);

		return $base->modify(
			sprintf('+%d days', $validityDays),
		);
	}

	/**
	 * The date a renewal window should extend from.
	 *
	 * A certificate that is still valid extends from its current expiry
	 * (so remaining time is preserved); an expired or never-granted one
	 * extends from now.
	 */
	private function renewalBase(
		ExtendedAccount $account,
	): \DateTimeImmutable {

		$now = $this->nowImmutable();
		$current = $account->getValidUntilImmutable();

		return ($current !== null && $current > $now)
			? $current
			: $now;
	}

	/**
	 * Returns the supplied timestamp formatted as an ATOM string.
	 *
	 * When no timestamp is supplied, the current UTC time is used.
	 */
	private function now(
		?\DateTimeInterface $dateTime = null,
	): string {
		return ($dateTime ?? $this->nowImmutable())
			->format(DATE_ATOM);
	}

	private function nowImmutable(): \DateTimeImmutable
	{
		return new \DateTimeImmutable(
			'now',
			new \DateTimeZone('UTC'),
		);
	}
}
