<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\Payment;

use OCA\Libresign\Db\Payment;
use OCA\Libresign\Db\PaymentMapper;
use Psr\Log\LoggerInterface;

/**
 * Read-only, admin-facing diagnostics for a single payment.
 *
 * Projects persisted payment data and provider metadata into structured facts
 * for the support UI. The UI owns translated headlines and support guidance,
 * using the normalized outcome reason.
 *
 * Does not modify payment state or trigger finalization, retries, or
 * reconciliation. Phone values are masked in diagnostic metadata; the full
 * phone number is exposed separately for explicit admin reveal. Missing or
 * malformed metadata should not prevent the remaining diagnostics from loading.
 */
class PaymentDiagnosticService {
	/** Provider-response keys that are safe to surface from a lifecycle stage. */
	private const STAGE_KEYS = [
		'resultcode', 'resultdesc', 'responsecode', 'responsedescription',
		'result', 'resultexplanation', 'status', 'statusdescription', 'code',
		'error', 'errormessage', 'customermessage',
		'checkoutrequestid', 'merchantrequestid', 'conversationid',
		'originatorconversationid', 'transtoken', 'transactiontoken', 'transid',
		'mpesareceiptnumber', 'reference', 'transactiondate',
	];

	/** Payment statuses that are terminal (no further provider progress expected). */
	private const TERMINAL_STATUSES = ['paid', 'failed', 'expired', 'cancelled', 'initiation_failed'];

	public function __construct(
		private PaymentMapper $paymentMapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return ?array<string, mixed> null when the payment does not exist
	 */
	public function forPaymentId(int $id): ?array {
		try {
			$payment = $this->paymentMapper->findById($id);
		} catch (\Throwable $e) {
			$this->logger->warning('[PaymentDiagnosticService] lookup failed', ['id' => $id, 'exception' => $e]);
			return null;
		}
		if (!$payment instanceof Payment) {
			return null;
		}

		$meta = [];
		try {
			$meta = $payment->getProviderMetadataDecoded();
		} catch (\Throwable $e) {
			$this->logger->warning('[PaymentDiagnosticService] metadata decode failed', ['id' => $id, 'exception' => $e]);
			$meta = [];
		}
		$stages = is_array($meta['providerPayload'] ?? null) ? $meta['providerPayload'] : [];

		return [
			'summary' => $this->summary($payment),
			'outcome' => $this->outcome($payment, $meta, $stages),
			'identifiers' => $this->identifiers($payment, $meta, $stages),
			'diagnostic' => $this->diagnostic($payment, $meta, $stages),
			'lifecycle' => [
				'initiation' => $this->normalizeStage($stages['initiation'] ?? null),
				'charge' => $this->normalizeStage($stages['charge'] ?? null),
				'callback' => $this->normalizeStage($stages['callback'] ?? null),
				'query' => $this->normalizeStage($stages['query'] ?? null),
				'verification' => $this->normalizeStage($stages['verification'] ?? null),
			],
			'verification' => $this->verification($payment),
			'raw' => $this->raw($meta),
			// Explicit full-row export for admin investigation. Values are unmasked to
			// support provider-side correlation; this export may contain sensitive data.
			'row' => $this->fullRow($payment),
			'finalState' => [
				'status' => $payment->getPaymentStatus()->value,
				// No direct payment-to-entitlement link exists, so this diagnostic
				// cannot confirm which entitlement, if any, was allocated.
				'allocationConfirmable' => false,
				'note' => 'Allocation cannot be confirmed from existing linkage.',
			],
		];
	}

	private function summary(Payment $payment): array {
		return [
			'id' => $payment->getId(),
			'attemptId' => $payment->getPaymentAttemptId(),
			'status' => $payment->getPaymentStatus()->value,
			'purpose' => $payment->getPaymentPurpose()->value,
			'provider' => $payment->getProvider(),
			'productCode' => $payment->getProductCode(),
			'productUses' => $payment->getProductUses(),
			'quantity' => $payment->getQuantity(),
			'amount' => $payment->getAmount(),
			'unitAmount' => $payment->getUnitAmount(),
			'currency' => $payment->getCurrency(),
			'displayAmount' => $payment->getDisplayAmount(),
			'displayCurrency' => $payment->getDisplayCurrency(),
			'createdAt' => $payment->getCreatedAtImmutable()?->format(DATE_ATOM),
			'updatedAt' => $payment->getUpdatedAtImmutable()?->format(DATE_ATOM),
			'paidAt' => $payment->getPaidAtImmutable()?->format(DATE_ATOM),
			'expiresAt' => $payment->getExpiresAtImmutable()?->format(DATE_ATOM),
			'phoneRegion' => $payment->getPhoneRegion(),
			'phoneCountry' => $payment->getPhoneCountry(),
			'phoneMasked' => $this->maskPhone($payment->getPhoneE164Digits()),
			// Full value for an explicit admin reveal/confirm (admin + flag gated).
			'phone' => $this->formatPhone($payment->getPhoneE164Digits()),
		];
	}

	/**
	 * The complete payment row as stored, snake_cased, datetimes as ISO-8601.
	 * Intended for an explicit admin "copy row" export, not the on-screen view.
	 *
	 * @return array<string, mixed>
	 */
	private function fullRow(Payment $payment): array {
		return [
			'id' => $payment->getId(),
			'purpose' => $payment->getPurpose(),
			'transaction_id' => $payment->getTransactionId(),
			'transaction_reference' => $payment->getTransactionReference(),
			'provider' => $payment->getProvider(),
			'provider_reference' => $payment->getProviderReference(),
			'provider_metadata' => $payment->getProviderMetadata(),
			'amount' => $payment->getAmount(),
			'unit_amount' => $payment->getUnitAmount(),
			'quantity' => $payment->getQuantity(),
			'currency' => $payment->getCurrency(),
			'status' => $payment->getStatus(),
			'payment_attempt_id' => $payment->getPaymentAttemptId(),
			'created_at' => $this->dt($payment->getCreatedAt()),
			'updated_at' => $this->dt($payment->getUpdatedAt()),
			'paid_at' => $this->dt($payment->getPaidAt()),
			'expires_at' => $this->dt($payment->getExpiresAt()),
			'user_id' => $payment->getUserId(),
			'product_code' => $payment->getProductCode(),
			'product_uses' => $payment->getProductUses(),
			'display_currency' => $payment->getDisplayCurrency(),
			'display_amount' => $payment->getDisplayAmount(),
			'fx_rate' => $payment->getFxRate(),
			'fx_rate_source' => $payment->getFxRateSource(),
			'fx_rate_locked_at' => $this->dt($payment->getFxRateLockedAt()),
			'phone_e164_digits' => $payment->getPhoneE164Digits(),
			'phone_region' => $payment->getPhoneRegion(),
			'phone_country' => $payment->getPhoneCountry(),
			'verification_status' => $payment->getVerificationStatus(),
			'verification_last_checked_at' => $this->dt($payment->getVerificationLastCheckedAt()),
			'verification_retry_count' => $payment->getVerificationRetryCount(),
			'next_verification_at' => $this->dt($payment->getNextVerificationAt()),
			'verification_locked_at' => $this->dt($payment->getVerificationLockedAt()),
			'last_error_code' => $payment->getLastErrorCode(),
			'last_error_message' => $payment->getLastErrorMessage(),
			'last_error_at' => $this->dt($payment->getLastErrorAt()),
		];
	}

	private function dt(?\DateTimeInterface $value): ?string {
		return $value?->format(DATE_ATOM);
	}

	/**
	 * Resolve the payment outcome facts used by the UI; translated headlines
	 * and support guidance are derived from the returned reason.
	 *
	 * @return array{kind: string, severity: string, reason: ?string, resultCode: ?int, resultDesc: ?string, source: ?string, stageFailed: ?string}
	 */
	private function outcome(Payment $payment, array $meta, array $stages): array {
		$status = $payment->getPaymentStatus()->value;
		if ($status === 'paid') {
			return [
				'kind' => 'success',
				'severity' => 'ok',
				'reason' => 'success',
				'resultCode' => null,
				'resultDesc' => null,
				'source' => null,
				'stageFailed' => null,
			];
		}

		$err = is_array($meta['providerError'] ?? null) ? $meta['providerError'] : [];
		$failing = $this->locateFailingStage($stages);

		// Reason precedence: the orchestrator's normalised reason/type, then a
		// Daraja result-code mapping from the failing stage, then the execution
		// state, then the persisted status.
		$reason = null;
		foreach ([$err['reason'] ?? null, $err['type'] ?? null] as $candidate) {
			if (is_string($candidate) && $candidate !== '' && $candidate !== 'failed') {
				$reason = $candidate;
				break;
			}
		}
		if ($reason === null && $failing['resultCode'] !== null) {
			$mapped = DarajaService::mapResultCodeToReason($failing['resultCode']);
			if ($mapped !== 'success') {
				$reason = $mapped;
			}
		}
		if ($reason === null) {
			$state = is_string($meta['providerExecutionState'] ?? null) ? $meta['providerExecutionState'] : null;
			if (in_array($state, ['failed', 'expired', 'cancelled', 'invalid_request'], true)) {
				$reason = $state;
			}
		}
		if ($reason === null) {
			$reason = in_array($status, ['failed', 'expired', 'cancelled', 'initiation_failed'], true) ? $status : null;
		}

		// Result code/description: provider error carries them for callback-stage
		// failures; otherwise take them from the failing stage.
		$resultCode = isset($err['resultCode']) && is_numeric($err['resultCode']) ? (int)$err['resultCode'] : $failing['resultCode'];
		$resultDesc = isset($err['resultDesc']) && is_scalar($err['resultDesc']) ? (string)$err['resultDesc'] : $failing['resultDesc'];
		$source = isset($err['source']) && is_string($err['source']) ? $err['source'] : null;

		return [
			'kind' => 'failure',
			'severity' => $this->severityFor($reason),
			'reason' => $reason,
			'resultCode' => $resultCode,
			'resultDesc' => $resultDesc,
			'source' => $source,
			'stageFailed' => $failing['stage'],
		];
	}

	private function severityFor(?string $reason): string {
		// Customer-driven, no-fault outcomes read as a warning; everything else
		// that didn't succeed is an error.
		return in_array($reason, ['cancelled', 'expired', 'transaction_in_progress'], true) ? 'warn' : 'error';
	}

	/**
	 * Find the stage that carries the failing result and pull its code/desc.
	 * Query stores the code under `raw.ResultCode`; callback stores it top-level.
	 *
	 * @param array<string, mixed> $stages
	 * @return array{stage: ?string, resultCode: ?int, resultDesc: ?string}
	 */
	private function locateFailingStage(array $stages): array {
		foreach (['callback', 'query'] as $name) {
			$stage = $stages[$name] ?? null;
			if (!is_array($stage)) {
				continue;
			}
			$raw = is_array($stage['raw'] ?? null) ? $stage['raw'] : $stage;
			$code = $raw['ResultCode'] ?? $stage['ResultCode'] ?? null;
			if (is_numeric($code) && (int)$code !== 0) {
				$desc = $raw['ResultDesc'] ?? $stage['ResultDesc'] ?? $stage['description'] ?? null;
				return [
					'stage' => $name,
					'resultCode' => (int)$code,
					'resultDesc' => is_scalar($desc) ? (string)$desc : null,
				];
			}
			$sstatus = $stage['status'] ?? null;
			if (is_string($sstatus) && in_array(strtoupper($sstatus), ['FAILED', 'CANCELLED'], true)) {
				$desc = $stage['description'] ?? ($raw['ResultDesc'] ?? null);
				return [
					'stage' => $name,
					'resultCode' => is_numeric($raw['ResultCode'] ?? null) ? (int)$raw['ResultCode'] : null,
					'resultDesc' => is_scalar($desc) ? (string)$desc : null,
				];
			}
		}
		return ['stage' => null, 'resultCode' => null, 'resultDesc' => null];
	}

	private function identifiers(Payment $payment, array $meta, array $stages): array {
		return [
			'transactionId' => $payment->getTransactionId(),
			'transactionReference' => $payment->getTransactionReference(),
			'providerReference' => $payment->getProviderReference(),
			'preferredProvider' => is_string($meta['preferredProvider'] ?? null) ? $meta['preferredProvider'] : null,
			'executedProvider' => is_string($meta['executedProvider'] ?? null) ? $meta['executedProvider'] : null,
			'checkoutRequestId' => $this->findInStages($stages, ['CheckoutRequestID', 'checkoutRequestId']),
			'merchantRequestId' => $this->findInStages($stages, ['MerchantRequestID', 'merchantRequestId']),
			'dpoTransactionToken' => $this->findInStages($stages, ['TransToken', 'TransactionToken', 'transToken']),
			'dpoTransactionRef' => $this->findInStages($stages, ['TransRef', 'transRef']),
			'mpesaReceipt' => $this->findMpesaReceipt($stages),
		];
	}

	private function diagnostic(Payment $payment, array $meta, array $stages): array {
		$providerError = null;
		if (is_array($meta['providerError'] ?? null)) {
			$e = $meta['providerError'];
			$providerError = [
				// Reason-style shape written by the orchestrator (the common case).
				'type' => $this->scalarOrNull($e['type'] ?? null),
				'reason' => $this->scalarOrNull($e['reason'] ?? null),
				'source' => $this->scalarOrNull($e['source'] ?? null),
				'resultCode' => isset($e['resultCode']) && is_numeric($e['resultCode']) ? (int)$e['resultCode'] : null,
				'resultDesc' => $this->scalarOrNull($e['resultDesc'] ?? null),
				'timestamp' => $this->scalarOrNull($e['timestamp'] ?? null),
				// Exception-style shape (kept for providers that record it).
				'message' => $this->scalarOrNull($e['message'] ?? null),
				'exception' => $this->scalarOrNull($e['exception'] ?? null),
				'code' => $this->scalarOrNull($e['code'] ?? null),
			];
		}

		$legacy = [
			'code' => $payment->getLastErrorCode(),
			'message' => $payment->getLastErrorMessage(),
			'at' => $payment->getLastErrorAtImmutable()?->format(DATE_ATOM),
		];

		$state = is_string($meta['providerExecutionState'] ?? null) ? $meta['providerExecutionState'] : null;
		$status = $payment->getPaymentStatus()->value;
		// The orchestrator sets providerError/terminal status but is known to leave
		// providerExecutionState at 'executing'. Surface that so the reader trusts
		// status + providerError, not the stuck state enum.
		$stateMismatch = $state === 'executing' && in_array($status, self::TERMINAL_STATUSES, true);

		return [
			'executionState' => $state,
			'stateMismatch' => $stateMismatch,
			'alreadyCharged' => (bool)($meta['alreadyCharged'] ?? false),
			'failureCategory' => $this->failureCategory($payment, $meta, $stages),
			'providerError' => $providerError,
			'legacyError' => ($legacy['code'] || $legacy['message'] || $legacy['at']) ? $legacy : null,
			'hasError' => $providerError !== null || $legacy['code'] || $legacy['message'],
		];
	}

	private function failureCategory(Payment $payment, array $meta, array $stages): ?string {
		if ($payment->getPaymentStatus()->value === 'paid') {
			return 'success';
		}
		$failing = $this->locateFailingStage($stages);
		if ($failing['resultCode'] !== null) {
			return DarajaService::mapResultCodeToReason($failing['resultCode']);
		}
		$state = is_string($meta['providerExecutionState'] ?? null) ? $meta['providerExecutionState'] : null;
		if (in_array($state, ['failed', 'expired', 'cancelled', 'invalid_request'], true)) {
			return $state;
		}
		$status = $payment->getPaymentStatus()->value;
		if (in_array($status, ['failed', 'expired', 'cancelled', 'initiation_failed'], true)) {
			return $status;
		}
		return null;
	}

	private function verification(Payment $payment): array {
		return [
			'status' => $payment->getVerificationStatus(),
			'lastCheckedAt' => $payment->getVerificationLastCheckedAtImmutable()?->format(DATE_ATOM),
			'retryCount' => $payment->getVerificationRetryCount(),
			'nextVerificationAt' => $payment->getNextVerificationAtImmutable()?->format(DATE_ATOM),
			'lockedAt' => $payment->getVerificationLockedAtImmutable()?->format(DATE_ATOM),
		];
	}

	/**
	 * Prepare decoded provider metadata for the Raw tab, masking phone values
	 * in phone-related keys and M-Pesa callback Name/Value items.
	 * Returns null when no metadata was decoded.
	 *
	 * @return ?array<string, mixed>
	 */
	private function raw(array $meta): ?array {
		if ($meta === []) {
			return null;
		}
		$sanitized = $this->sanitizeRaw($meta);
		return is_array($sanitized) ? $sanitized : null;
	}

	/**
	 * @param mixed $node
	 * @return mixed
	 */
	private function sanitizeRaw(mixed $node): mixed {
		if (!is_array($node)) {
			return $node;
		}
		// M-Pesa CallbackMetadata item: { "Name": "PhoneNumber", "Value": 2547... }
		if (isset($node['Name']) && array_key_exists('Value', $node)
			&& is_string($node['Name']) && preg_match('/phone/i', $node['Name'])
			&& is_scalar($node['Value'])) {
			return ['Name' => $node['Name'], 'Value' => $this->maskDigitsValue((string)$node['Value'])];
		}
		$out = [];
		foreach ($node as $key => $value) {
			if (is_string($key) && preg_match('/phone/i', $key) && is_scalar($value) && (string)$value !== '') {
				$out[$key] = $this->maskDigitsValue((string)$value);
			} else {
				$out[$key] = $this->sanitizeRaw($value);
			}
		}
		return $out;
	}

	private function findMpesaReceipt(array $stages): ?string {
		$callback = $stages['callback'] ?? null;
		if (!is_array($callback)) {
			return null;
		}
		$items = $callback['CallbackMetadata']['Item'] ?? null;
		if (!is_array($items)) {
			return null;
		}
		foreach ($items as $item) {
			if (is_array($item) && ($item['Name'] ?? null) === 'MpesaReceiptNumber' && is_scalar($item['Value'] ?? null)) {
				return (string)$item['Value'];
			}
		}
		return null;
	}

	private function scalarOrNull(mixed $v): ?string {
		return is_scalar($v) ? (string)$v : null;
	}

	/**
	 * @param mixed $stage
	 * @return ?array{recorded: bool, fields: array<string, string>}
	 */
	private function normalizeStage(mixed $stage): ?array {
		if (!is_array($stage)) {
			return null; // never reached / not recorded
		}
		$fields = [];
		foreach ($stage as $key => $value) {
			if (!is_scalar($value)) {
				continue; // skip nested structures (avoids PII leakage + noise)
			}
			if (in_array(strtolower((string)$key), self::STAGE_KEYS, true)) {
				$fields[(string)$key] = (string)$value;
			}
		}
		// Query nests its codes under `raw`; lift the safe ones so the stage isn't
		// shown as empty when it actually carries the failure.
		if (is_array($stage['raw'] ?? null)) {
			foreach ($stage['raw'] as $key => $value) {
				if (is_scalar($value) && in_array(strtolower((string)$key), self::STAGE_KEYS, true)) {
					$fields[(string)$key] = (string)$value;
				}
			}
		}
		return ['recorded' => true, 'fields' => $fields];
	}

	/**
	 * @param array<string, mixed> $stages
	 * @param list<string> $keys
	 */
	private function findInStages(array $stages, array $keys): ?string {
		foreach ($stages as $stage) {
			if (!is_array($stage)) {
				continue;
			}
			foreach ($keys as $key) {
				if (isset($stage[$key]) && is_scalar($stage[$key]) && (string)$stage[$key] !== '') {
					return (string)$stage[$key];
				}
			}
		}
		return null;
	}

	private function maskPhone(?string $digits): ?string {
		$d = preg_replace('/\D/', '', (string)$digits);
		if ($d === '' || $d === null) {
			return null;
		}
		if (strlen($d) <= 4) {
			return str_repeat('•', strlen($d));
		}
		return '+' . substr($d, 0, 3) . str_repeat('•', max(0, strlen($d) - 6)) . substr($d, -3);
	}

	private function maskDigitsValue(string $value): string {
		$d = preg_replace('/\D/', '', $value);
		if ($d === '' || $d === null || strlen($d) <= 4) {
			return str_repeat('•', max(1, strlen((string)$d)));
		}
		return substr($d, 0, 3) . str_repeat('•', max(0, strlen($d) - 6)) . substr($d, -3);
	}

	private function formatPhone(?string $digits): ?string {
		$d = preg_replace('/\D/', '', (string)$digits);
		return ($d === '' || $d === null) ? null : '+' . $d;
	}
}
