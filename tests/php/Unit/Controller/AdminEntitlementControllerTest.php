<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Controller\AdminEntitlementController;
use OCA\Libresign\Db\Entitlement;
use OCA\Libresign\Db\Payment;
use OCA\Libresign\Db\PaymentMapper;
use OCA\Libresign\Enum\EntitlementType;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Service\Entitlement\EntitlementService;
use OCA\Libresign\Service\Entitlement\ReservationInspectionService;
use OCA\Libresign\Service\Payment\PaymentDiagnosticService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\AppFramework\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Covers the admin entitlement endpoints: feature-flag + admin gating,
 * strict quantity validation, product restriction, and overview shaping.
 */
final class AdminEntitlementControllerTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private IGroupManager&MockObject $groupManager;
	private IUserSession&MockObject $userSession;
	private IUserManager&MockObject $userManager;
	private EntitlementService&MockObject $entitlementService;
	private PaymentMapper&MockObject $paymentMapper;
	private ReservationInspectionService&MockObject $reservationInspection;
	private PaymentDiagnosticService&MockObject $paymentDiagnostics;
	private AdminEntitlementController $controller;

	public function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->entitlementService = $this->createMock(EntitlementService::class);
		$this->paymentMapper = $this->createMock(PaymentMapper::class);
		$this->reservationInspection = $this->createMock(ReservationInspectionService::class);
		$this->paymentDiagnostics = $this->createMock(PaymentDiagnosticService::class);

		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($admin);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text) => $text);

		$this->controller = new AdminEntitlementController(
			$this->createMock(IRequest::class),
			$this->appConfig,
			$this->groupManager,
			$this->userSession,
			$this->userManager,
			$l10n,
			$this->createMock(LoggerInterface::class),
			$this->entitlementService,
			$this->paymentMapper,
			$this->reservationInspection,
			$this->paymentDiagnostics,
		);
	}

	private function enableFeatureAndAdmin(bool $flag = true, bool $admin = true): void {
		$this->appConfig->method('getValueBool')
			->willReturnCallback(fn (string $app, string $key, bool $default = false): bool
				=> $app === Application::APP_ID && $key === 'admin_entitlement_grants_enabled' ? $flag : $default);
		$this->groupManager->method('isAdmin')->willReturn($admin);
	}

	private function recipient(): IUser {
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn('u-1');
		$u->method('getDisplayName')->willReturn('Asha Mwangi');
		$u->method('getEMailAddress')->willReturn('asha@example.co.ke');
		$u->method('isEnabled')->willReturn(true);
		return $u;
	}

	private function fakeEntitlement(): Entitlement {
		$e = new Entitlement();
		$e->setId(10);
		$e->setUserId('u-1');
		$e->setProductCode('SIGN_DOCUMENT');
		$e->setRemainingUses(5);
		$e->setEntitlementType(EntitlementType::ADMIN_GRANTED);
		return $e;
	}

	private function fakeOverview(): array {
		return [
			'summary' => ['productCode' => 'SIGN_DOCUMENT', 'availableUses' => 8, 'remainingUses' => 9, 'reservedUses' => 1, 'activeEntitlements' => 2],
			'entitlements' => [],
		];
	}

	public function testGrantSuccess(): void {
		$this->enableFeatureAndAdmin();
		$this->userManager->method('get')->with('u-1')->willReturn($this->recipient());
		$this->entitlementService->expects($this->once())->method('grant')
			->with('u-1', 'SIGN_DOCUMENT', 5, 'admin', 'blocked', null)
			->willReturn($this->fakeEntitlement());
		$this->entitlementService->method('getProductOverview')->willReturn($this->fakeOverview());

		$response = $this->controller->grant('u-1', 5, 'blocked');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(10, $data['entitlement']['id']);
		$this->assertSame(8, $data['summary']['availableUses']);
	}

	public static function boundaryProvider(): array {
		return [
			'min 1' => [1, true],
			'max 100' => [100, true],
			'zero' => [0, false],
			'over 100' => [101, false],
			'float' => [1.5, false],
			'non-numeric string' => ['abc', false],
			'decimal string' => ['1.5', false],
			'negative' => [-5, false],
		];
	}

	#[DataProvider('boundaryProvider')]
	public function testGrantQuantityValidation(mixed $uses, bool $accepted): void {
		$this->enableFeatureAndAdmin();
		$this->userManager->method('get')->willReturn($this->recipient());
		$this->entitlementService->method('getProductOverview')->willReturn($this->fakeOverview());

		if ($accepted) {
			$this->entitlementService->expects($this->once())->method('grant')->willReturn($this->fakeEntitlement());
			$response = $this->controller->grant('u-1', $uses, 'reason');
			$this->assertSame(Http::STATUS_OK, $response->getStatus());
		} else {
			$this->entitlementService->expects($this->never())->method('grant');
			$response = $this->controller->grant('u-1', $uses, 'reason');
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		}
	}

	public function testGrantAllowsEmptyReason(): void {
		// Reason is optional: a blank/whitespace reason trims to '' and the grant proceeds.
		$this->enableFeatureAndAdmin();
		$this->userManager->method('get')->willReturn($this->recipient());
		$this->entitlementService->method('getProductOverview')->willReturn($this->fakeOverview());
		$this->entitlementService->expects($this->once())->method('grant')
			->with('u-1', 'SIGN_DOCUMENT', 5, 'admin', '', null)
			->willReturn($this->fakeEntitlement());
		$response = $this->controller->grant('u-1', 5, '   ');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testGrantRejectsCertificateAccess(): void {
		$this->enableFeatureAndAdmin();
		$this->entitlementService->expects($this->never())->method('grant');
		$response = $this->controller->grant('u-1', 5, 'reason', 'CERTIFICATE_ACCESS');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testGrantRejectsUnknownProduct(): void {
		$this->enableFeatureAndAdmin();
		$this->entitlementService->expects($this->never())->method('grant');
		$response = $this->controller->grant('u-1', 5, 'reason', 'FOO');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testGrantDefaultsToSignDocument(): void {
		$this->enableFeatureAndAdmin();
		$this->userManager->method('get')->willReturn($this->recipient());
		$this->entitlementService->method('getProductOverview')->willReturn($this->fakeOverview());
		$this->entitlementService->expects($this->once())->method('grant')
			->with('u-1', 'SIGN_DOCUMENT', 5, 'admin', 'reason', null)
			->willReturn($this->fakeEntitlement());
		$response = $this->controller->grant('u-1', 5, 'reason', '');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testGrantUnknownUserReturns404(): void {
		$this->enableFeatureAndAdmin();
		$this->userManager->method('get')->willReturn(null);
		$this->entitlementService->expects($this->never())->method('grant');
		$response = $this->controller->grant('ghost', 5, 'reason');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testNonAdminForbidden(): void {
		$this->enableFeatureAndAdmin(true, false);
		$this->entitlementService->expects($this->never())->method('grant');
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->grant('u-1', 5, 'reason')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->overview('u-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->searchUsers('asha')->getStatus());
	}

	public function testFeatureDisabledForbidden(): void {
		$this->enableFeatureAndAdmin(false, true);
		$this->entitlementService->expects($this->never())->method('grant');
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->grant('u-1', 5, 'reason')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->overview('u-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->searchUsers('asha')->getStatus());
	}

	public function testOverviewReturnsBoundedPayments(): void {
		$this->enableFeatureAndAdmin();
		$this->userManager->method('get')->with('u-1')->willReturn($this->recipient());
		$this->entitlementService->method('getProductOverview')->willReturn($this->fakeOverview());

		$payment = $this->createMock(Payment::class);
		$payment->method('getId')->willReturn(99);
		$payment->method('getCreatedAtImmutable')->willReturn(new \DateTimeImmutable('2026-10-12T09:14:00+00:00'));
		$payment->method('getPaidAtImmutable')->willReturn(null);
		$payment->method('getPaymentStatus')->willReturn(PaymentStatus::PAID);
		$payment->method('getPaymentPurpose')->willReturn(PaymentPurpose::CREDIT_PURCHASE);
		$payment->method('getProvider')->willReturn('daraja');
		$payment->method('getAmount')->willReturn(40000);
		$payment->method('getCurrency')->willReturn('KES');
		$payment->method('getDisplayAmount')->willReturn(null);
		$payment->method('getDisplayCurrency')->willReturn(null);
		$payment->method('getProviderReference')->willReturn('SFG6H2K9QX');

		$this->paymentMapper->expects($this->once())
			->method('findRecentByUserId')
			->with('u-1', 30)
			->willReturn([$payment]);

		$response = $this->controller->overview('u-1');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('SIGN_DOCUMENT', $data['product']);
		$this->assertCount(1, $data['payments']);
		$this->assertSame('paid', $data['payments'][0]['status']);
		$this->assertSame('u-1', $data['user']['uid']);
	}

	public function testOverviewUnknownUserReturns404(): void {
		$this->enableFeatureAndAdmin();
		$this->userManager->method('get')->willReturn(null);
		$response = $this->controller->overview('ghost');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testSearchShortQueryReturnsEmpty(): void {
		$this->enableFeatureAndAdmin();
		$this->userManager->expects($this->never())->method('getByEmail');
		$response = $this->controller->searchUsers('ab');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData()['users']);
	}

	public function testSearchReturnsDedupedUsers(): void {
		$this->enableFeatureAndAdmin();
		$this->userManager->method('getByEmail')->willReturn([$this->recipient()]);
		$this->userManager->method('searchDisplayName')->willReturn([$this->recipient()]);
		$response = $this->controller->searchUsers('asha@example.co.ke');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData()['users']);
		$this->assertSame('u-1', $response->getData()['users'][0]['uid']);
	}
	public function testOverviewEnrichesReservedEntitlements(): void {
		$this->enableFeatureAndAdmin();
		$this->userManager->method('get')->willReturn($this->recipient());
		$this->entitlementService->method('getProductOverview')->willReturn([
			'summary' => ['productCode' => 'SIGN_DOCUMENT', 'availableUses' => 2, 'remainingUses' => 5, 'reservedUses' => 3, 'activeEntitlements' => 1],
			'entitlements' => [
				['id' => 7, 'type' => 'PAY_AS_YOU_GO', 'remainingUses' => 5, 'reservedUses' => 3, 'availableUses' => 2, 'expiresAt' => null, 'createdAt' => null, 'usable' => true],
				['id' => 8, 'type' => 'PAY_AS_YOU_GO', 'remainingUses' => 1, 'reservedUses' => 0, 'availableUses' => 1, 'expiresAt' => null, 'createdAt' => null, 'usable' => true],
			],
		]);
		$resv = [['signRequestId' => 9, 'quantity' => 3, 'createdAt' => null, 'sponsored' => true, 'signerName' => 'Bob']];
		$this->reservationInspection->expects($this->once())->method('forEntitlement')->with(7)->willReturn($resv);
		$this->paymentMapper->method('findRecentByUserId')->willReturn([]);

		$data = $this->controller->overview('u-1')->getData();

		$this->assertSame($resv, $data['entitlements'][0]['reservations']);
		$this->assertArrayNotHasKey('reservations', $data['entitlements'][1]);
	}

	public function testPaymentDetailSuccess(): void {
		$this->enableFeatureAndAdmin();
		$detail = ['summary' => ['id' => 7, 'status' => 'paid']];
		$this->paymentDiagnostics->expects($this->once())->method('forPaymentId')->with(7)->willReturn($detail);

		$response = $this->controller->paymentDetail(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($detail, $response->getData()['payment']);
	}

	public function testPaymentDetailNotFound(): void {
		$this->enableFeatureAndAdmin();
		$this->paymentDiagnostics->method('forPaymentId')->willReturn(null);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->paymentDetail(7)->getStatus());
	}

	public function testPaymentDetailForbiddenWhenNotAdmin(): void {
		$this->enableFeatureAndAdmin(true, false);
		$this->paymentDiagnostics->expects($this->never())->method('forPaymentId');
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->paymentDetail(7)->getStatus());
	}

	public function testPaymentDetailForbiddenWhenFlagDisabled(): void {
		$this->enableFeatureAndAdmin(false, true);
		$this->paymentDiagnostics->expects($this->never())->method('forPaymentId');
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->paymentDetail(7)->getStatus());
	}
}


