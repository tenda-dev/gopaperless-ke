<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\Payment;
use OCA\Libresign\Db\PaymentMapper;
use OCA\Libresign\Enum\ProductCode;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\Entitlement\EntitlementService;
use OCA\Libresign\Service\Entitlement\ReservationInspectionService;
use OCA\Libresign\Service\Payment\PaymentDiagnosticService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Admin-facing support tools for inspecting document-signing entitlements,
 * reviewing recent payment context, and granting SIGN_DOCUMENT credits.
 *
 * Access requires both the admin_entitlement_grants_enabled feature flag and
 * administrator privileges. The explicit checks complement the framework's
 * admin gate, which remains enabled because the endpoints do not use
 * #[NoAdminRequired].
 *
 * Grants are limited to SIGN_DOCUMENT. Payment inspection is read-only and
 * does not reconcile payments or maintain a separate grant ledger.
 */
class AdminEntitlementController extends AEnvironmentAwareController {
	/** The only product a grant may target. */
	private const PRODUCT = ProductCode::SIGN_DOCUMENT;
	private const SEARCH_MIN = 3;
	private const SEARCH_LIMIT = 25;
	private const PAYMENTS_LIMIT = 30;
	private const USES_MIN = 1;
	private const USES_MAX = 100;

	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private IUserManager $userManager,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private EntitlementService $entitlementService,
		private PaymentMapper $paymentMapper,
		private ReservationInspectionService $reservationInspection,
		private PaymentDiagnosticService $paymentDiagnostics,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function assertFeatureEnabled(): void {
		if (!$this->appConfig->getValueBool(Application::APP_ID, 'admin_entitlement_grants_enabled', false)) {
			throw new LibresignException($this->l10n->t('Admin entitlement grants are disabled'), Http::STATUS_FORBIDDEN);
		}
	}

	private function assertAdminUser(): void {
		if (!$this->groupManager->isAdmin($this->userSession->getUser()?->getUID() ?? '')) {
			throw new LibresignException($this->l10n->t('Unauthorized'), Http::STATUS_FORBIDDEN);
		}
	}

	/**
	 * Search accounts by email (exact) or display name / uid (prefix).
	 */
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/admin/entitlements/users/search', requirements: ['apiVersion' => '(v1)'])]
	public function searchUsers(string $search = ''): DataResponse {
		try {
			$this->assertFeatureEnabled();
			$this->assertAdminUser();

			$search = trim($search);
			if (mb_strlen($search) < self::SEARCH_MIN) {
				return new DataResponse(['users' => []]);
			}

			/** @var array<string, IUser> $found */
			$found = [];
			foreach ($this->userManager->getByEmail($search) as $u) {
				$found[$u->getUID()] = $u;
			}
			foreach ($this->userManager->searchDisplayName($search, self::SEARCH_LIMIT) as $u) {
				$found[$u->getUID()] = $u;
			}

			$users = [];
			foreach (array_slice($found, 0, self::SEARCH_LIMIT, true) as $u) {
				$users[] = $this->serializeUser($u);
			}
			return new DataResponse(['users' => $users]);
		} catch (LibresignException $e) {
			return new DataResponse(['users' => [], 'error' => $e->getMessage()], $this->statusOf($e, Http::STATUS_FORBIDDEN));
		} catch (\Throwable $e) {
			$this->logger->error('Admin entitlement user search failed', ['exception' => $e]);
			return new DataResponse(['users' => [], 'error' => 'Search failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Account overview: identity, SIGN_DOCUMENT entitlement summary + rows,
	 * and up to 30 most-recent payments (newest first) as read-only context.
	 */
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/admin/entitlements/users/{uid}/overview', requirements: ['apiVersion' => '(v1)'])]
	public function overview(string $uid): DataResponse {
		try {
			$this->assertFeatureEnabled();
			$this->assertAdminUser();

			$user = $this->userManager->get($uid);
			if (!$user instanceof IUser) {
				throw new LibresignException($this->l10n->t('User not found'), Http::STATUS_NOT_FOUND);
			}

			$product = self::PRODUCT->value;
			$overview = $this->entitlementService->getProductOverview($uid, $product);

			// Include active reservation details only for entitlements with reserved
			// uses; this enriches the support view without changing reservation state.
			$entitlements = $overview['entitlements'];
			foreach ($entitlements as &$entitlement) {
				if ((int)($entitlement['reservedUses'] ?? 0) > 0) {
					$entitlement['reservations'] = $this->reservationInspection->forEntitlement((int)$entitlement['id']);
				}
			}
			unset($entitlement);

			$payments = array_map(
				fn (Payment $p): array => $this->serializePayment($p),
				$this->paymentMapper->findRecentByUserId($uid, self::PAYMENTS_LIMIT),
			);

			return new DataResponse([
				'user' => $this->serializeUser($user),
				'product' => $product,
				'summary' => $overview['summary'],
				'entitlements' => $entitlements,
				'payments' => $payments,
			]);
		} catch (LibresignException $e) {
			return new DataResponse(['error' => $e->getMessage()], $this->statusOf($e, Http::STATUS_BAD_REQUEST));
		} catch (\Throwable $e) {
			$this->logger->error('Admin entitlement overview failed', ['exception' => $e, 'uid' => $uid]);
			return new DataResponse(['error' => 'Overview failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Grant SIGN_DOCUMENT credits to a user.
	 *
	 * `uses` is intentionally untyped so a non-integer (e.g. 1.5 or "abc") is
	 * rejected rather than silently coerced. Returns the created entitlement and
	 * a freshly read authoritative summary (no client-side projection of truth).
	 *
	 * @param mixed $uses
	 */
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/admin/entitlements/grant', requirements: ['apiVersion' => '(v1)'])]
	public function grant(string $uid, $uses = null, string $reason = '', string $productCode = '', string $reference = ''): DataResponse {
		try {
			$this->assertFeatureEnabled();
			$this->assertAdminUser();

			$product = $this->resolveSupportedProduct($productCode);
			$usesInt = $this->parseUses($uses);

			// Keep the reason optional for operational grants; normalize it here before
			// passing it to the service for handling.
			$reason = trim($reason);

			$recipient = $this->userManager->get($uid);
			if (!$recipient instanceof IUser) {
				throw new LibresignException($this->l10n->t('User not found'), Http::STATUS_NOT_FOUND);
			}

			$reference = trim($reference);
			$entitlement = $this->entitlementService->grant(
				$recipient->getUID(),
				$product,
				$usesInt,
				$this->userSession->getUser()?->getUID() ?? '',
				$reason,
				$reference !== '' ? $reference : null,
			);

			$overview = $this->entitlementService->getProductOverview($recipient->getUID(), $product);

			return new DataResponse([
				'success' => true,
				'entitlement' => [
					'id' => $entitlement->getId(),
					'productCode' => $entitlement->getProductCode(),
					'type' => $entitlement->getType(),
					'remainingUses' => $entitlement->getRemainingUses(),
					'createdAt' => $entitlement->getCreatedAt()?->format(DATE_ATOM),
				],
				'summary' => $overview['summary'],
			]);
		} catch (LibresignException $e) {
			return new DataResponse(['success' => false, 'error' => $e->getMessage()], $this->statusOf($e, Http::STATUS_BAD_REQUEST));
		} catch (\Throwable $e) {
			$this->logger->error('Admin entitlement grant failed', ['exception' => $e, 'uid' => $uid]);
			return new DataResponse(['success' => false, 'error' => 'Grant failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Read-only diagnostic detail for a single payment (support investigation).
	 */
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/admin/entitlements/payments/{id}', requirements: ['apiVersion' => '(v1)', 'id' => '\\d+'])]
	public function paymentDetail(int $id): DataResponse {
		try {
			$this->assertFeatureEnabled();
			$this->assertAdminUser();

			$detail = $this->paymentDiagnostics->forPaymentId($id);
			if ($detail === null) {
				throw new LibresignException($this->l10n->t('Payment not found'), Http::STATUS_NOT_FOUND);
			}

			return new DataResponse(['payment' => $detail]);
		} catch (LibresignException $e) {
			return new DataResponse(['error' => $e->getMessage()], $this->statusOf($e, Http::STATUS_BAD_REQUEST));
		} catch (\Throwable $e) {
			$this->logger->error('Admin payment detail failed', ['exception' => $e, 'id' => $id]);
			return new DataResponse(['error' => 'Payment detail failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function resolveSupportedProduct(string $productCode): string {
		$code = strtoupper(trim($productCode));
		if ($code === '') {
			return self::PRODUCT->value;
		}
		if ($code !== self::PRODUCT->value) {
			throw new LibresignException($this->l10n->t('Only document-signing credits can be granted'), Http::STATUS_BAD_REQUEST);
		}
		return self::PRODUCT->value;
	}

	/**
	 * @param mixed $raw
	 */
	private function parseUses($raw): int {
		$n = null;
		if (is_int($raw)) {
			$n = $raw;
		} elseif (is_string($raw) && $raw !== '' && ctype_digit($raw)) {
			$n = (int)$raw;
		}
		if ($n === null) {
			throw new LibresignException($this->l10n->t('Quantity must be a whole number'), Http::STATUS_BAD_REQUEST);
		}
		if ($n < self::USES_MIN || $n > self::USES_MAX) {
			throw new LibresignException(
				$this->l10n->t('Quantity must be between %1$s and %2$s', [(string)self::USES_MIN, (string)self::USES_MAX]),
				Http::STATUS_BAD_REQUEST,
			);
		}
		return $n;
	}

	private function statusOf(LibresignException $e, int $fallback): int {
		$code = $e->getCode();
		return $code >= 400 && $code < 600 ? $code : $fallback;
	}

	private function serializeUser(IUser $user): array {
		return [
			'uid' => $user->getUID(),
			'displayName' => $user->getDisplayName(),
			'email' => $user->getEMailAddress(),
			'enabled' => $user->isEnabled(),
		];
	}

	private function serializePayment(Payment $payment): array {
		return [
			'id' => $payment->getId(),
			'createdAt' => $payment->getCreatedAtImmutable()?->format(DATE_ATOM),
			'paidAt' => $payment->getPaidAtImmutable()?->format(DATE_ATOM),
			'status' => $payment->getPaymentStatus()->value,
			'purpose' => $payment->getPaymentPurpose()->value,
			'provider' => $payment->getProvider(),
			'amount' => $payment->getAmount(),
			'currency' => $payment->getCurrency(),
			'displayAmount' => $payment->getDisplayAmount(),
			'displayCurrency' => $payment->getDisplayCurrency(),
			'providerReference' => $payment->getProviderReference(),
		];
	}
}
