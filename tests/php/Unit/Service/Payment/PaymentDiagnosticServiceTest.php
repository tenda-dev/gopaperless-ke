<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Payment;

use OCA\Libresign\Db\Payment;
use OCA\Libresign\Db\PaymentMapper;
use OCA\Libresign\Enum\PaymentPurpose;
use OCA\Libresign\Enum\PaymentStatus;
use OCA\Libresign\Service\Payment\PaymentDiagnosticService;
use OCA\Libresign\Tests\Unit\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class PaymentDiagnosticServiceTest extends TestCase {
	private PaymentMapper&MockObject $paymentMapper;
	private PaymentDiagnosticService $service;

	public function setUp(): void {
		parent::setUp();
		$this->paymentMapper = $this->createMock(PaymentMapper::class);
		$this->service = new PaymentDiagnosticService(
			$this->paymentMapper,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function payment(string $status, string $metadataJson, string $provider = 'daraja'): Payment {
		$p = new Payment();
		$p->setId(42);
		$p->setPaymentAttemptId('attempt-1');
		$p->setProvider($provider);
		$p->setProductCode('SIGN_DOCUMENT');
		$p->setProductUses(1);
		$p->setQuantity(5);
		$p->setAmount(50000);
		$p->setUnitAmount(10000);
		$p->setCurrency('KES');
		$p->setStatus($status);
		$p->setPurpose(PaymentPurpose::CREDIT_PURCHASE->value);
		$p->setPhoneE164Digits('254712345678');
		$p->setPhoneRegion('KE');
		$p->setPhoneCountry('KE');
		$p->setTransactionReference('txref');
		$p->setProviderReference('provref');
		$p->setProviderMetadata($metadataJson);
		return $p;
	}

	public function testReturnsNullWhenPaymentMissing(): void {
		$this->paymentMapper->method('findById')->willReturn(null);
		$this->assertNull($this->service->forPaymentId(99));
	}

	public function testPaidPaymentBuildsSections(): void {
		$meta = json_encode([
			'preferredProvider' => 'daraja',
			'executedProvider' => 'daraja',
			'providerExecutionState' => 'success',
			'alreadyCharged' => true,
			'providerPayload' => [
				'initiation' => ['CheckoutRequestID' => 'ws_CO_1', 'MerchantRequestID' => 'mr_1', 'ResponseCode' => '0'],
				'callback' => ['ResultCode' => 0, 'ResultDesc' => 'Success'],
			],
		]);
		$this->paymentMapper->method('findById')->willReturn($this->payment(PaymentStatus::PAID->value, $meta));

		$out = $this->service->forPaymentId(42);

		$this->assertSame('paid', $out['summary']['status']);
		$this->assertSame('success', $out['diagnostic']['failureCategory']);
		$this->assertSame('ws_CO_1', $out['identifiers']['checkoutRequestId']);
		$this->assertSame('mr_1', $out['identifiers']['merchantRequestId']);
		$this->assertTrue($out['lifecycle']['initiation']['recorded']);
		$this->assertArrayHasKey('ResultDesc', $out['lifecycle']['callback']['fields']);
		$this->assertNull($out['lifecycle']['query']);
		$this->assertFalse($out['finalState']['allocationConfirmable']);
		// phone masked by default, full value available for reveal
		$this->assertStringContainsString('254', (string)$out['summary']['phoneMasked']);
		$this->assertStringContainsString('•', (string)$out['summary']['phoneMasked']);
		$this->assertSame('+254712345678', $out['summary']['phone']);
		// outcome facts
		$this->assertSame('success', $out['outcome']['kind']);
		$this->assertSame('ok', $out['outcome']['severity']);
		$this->assertSame('success', $out['outcome']['reason']);
		$this->assertNull($out['outcome']['stageFailed']);
		// state did advance to 'success' here, so no mismatch
		$this->assertFalse($out['diagnostic']['stateMismatch']);
	}

	public function testCancelledViaQueryStageReasonStyleError(): void {
		// Real shape (payment 28): reason-style providerError + query.raw.ResultCode.
		$meta = json_encode([
			'providerExecutionState' => 'executing',
			'alreadyCharged' => true,
			'providerPayload' => [
				'initiation' => ['CheckoutRequestID' => 'ws_CO_1', 'ResponseCode' => '0'],
				'query' => [
					'status' => 'CANCELLED',
					'reason' => 'cancelled',
					'raw' => ['ResultCode' => '1032', 'ResultDesc' => 'Request Cancelled by user.'],
					'description' => 'Request Cancelled by user.',
				],
			],
			'providerError' => ['type' => 'cancelled', 'reason' => 'cancelled', 'source' => 'daraja_query', 'timestamp' => '2026-07-02T23:56:12+00:00'],
		]);
		$this->paymentMapper->method('findById')->willReturn($this->payment(PaymentStatus::CANCELLED->value, $meta));

		$out = $this->service->forPaymentId(42);
		$o = $out['outcome'];

		$this->assertSame('failure', $o['kind']);
		$this->assertSame('cancelled', $o['reason']);
		$this->assertSame('warn', $o['severity']); // customer-driven, no-fault
		$this->assertSame(1032, $o['resultCode']);
		$this->assertStringContainsString('Cancelled', (string)$o['resultDesc']);
		$this->assertSame('daraja_query', $o['source']);
		$this->assertSame('query', $o['stageFailed']);
		// providerError reason-style fields are surfaced (the old allowlist dropped these)
		$this->assertSame('cancelled', $out['diagnostic']['providerError']['reason']);
		$this->assertSame('daraja_query', $out['diagnostic']['providerError']['source']);
		// query nests its code under raw; normalizeStage lifts it so the stage isn't "empty"
		$this->assertArrayHasKey('ResultCode', $out['lifecycle']['query']['fields']);
		// executing + terminal status => surfaced mismatch
		$this->assertTrue($out['diagnostic']['stateMismatch']);
	}

	public function testWrongPinQueryStageUsesReasonNotType(): void {
		// payment 30: type=failed but reason=wrong_pin; reason must win over the bare type.
		$meta = json_encode([
			'providerExecutionState' => 'executing',
			'providerPayload' => [
				'query' => [
					'status' => 'FAILED',
					'reason' => 'wrong_pin',
					'raw' => ['ResultCode' => '2001', 'ResultDesc' => 'The initiator information is invalid.'],
				],
			],
			'providerError' => ['type' => 'failed', 'reason' => 'wrong_pin', 'source' => 'daraja_query'],
		]);
		$this->paymentMapper->method('findById')->willReturn($this->payment(PaymentStatus::FAILED->value, $meta));

		$o = $this->service->forPaymentId(42)['outcome'];
		$this->assertSame('wrong_pin', $o['reason']);
		$this->assertSame('error', $o['severity']);
		$this->assertSame('query', $o['stageFailed']);
		$this->assertSame(2001, $o['resultCode']);
	}

	public function testTimeoutCallbackStage(): void {
		// payment 32: callback top-level ResultCode 1037, providerError carries resultCode/resultDesc.
		$meta = json_encode([
			'providerExecutionState' => 'executing',
			'providerPayload' => [
				'callback' => ['CheckoutRequestID' => 'ws_CO_1', 'ResultCode' => 1037, 'ResultDesc' => 'No response from user.'],
			],
			'providerError' => ['type' => 'failed', 'reason' => 'timeout', 'resultCode' => 1037, 'resultDesc' => 'No response from user.', 'source' => 'daraja_callback'],
		]);
		$this->paymentMapper->method('findById')->willReturn($this->payment(PaymentStatus::FAILED->value, $meta));

		$o = $this->service->forPaymentId(42)['outcome'];
		$this->assertSame('timeout', $o['reason']);
		$this->assertSame('callback', $o['stageFailed']);
		$this->assertSame(1037, $o['resultCode']);
		$this->assertSame('daraja_callback', $o['source']);
	}

	public function testExpiredNoProviderResponse(): void {
		// payment 31: expired, no callback/query recorded, no result code.
		$meta = json_encode([
			'providerExecutionState' => 'executing',
			'providerPayload' => [
				'initiation' => ['CheckoutRequestID' => 'ws_CO_1', 'ResponseCode' => '0'],
				'callback' => null,
				'query' => null,
			],
			'providerError' => ['type' => 'expired', 'reason' => 'expired', 'timestamp' => '2026-07-03T09:47:28+00:00'],
		]);
		$this->paymentMapper->method('findById')->willReturn($this->payment(PaymentStatus::FAILED->value, $meta));

		$o = $this->service->forPaymentId(42)['outcome'];
		$this->assertSame('expired', $o['reason']);
		$this->assertSame('warn', $o['severity']);
		$this->assertNull($o['resultCode']);
		$this->assertNull($o['stageFailed']);
	}

	public function testExceptionStyleProviderErrorStillSupported(): void {
		// Older exception-style providerError (message/exception) + callback code 1032.
		$meta = json_encode([
			'providerExecutionState' => 'failed',
			'providerError' => ['message' => 'email is required', 'exception' => 'RuntimeException'],
			'providerPayload' => [
				'callback' => ['ResultCode' => 1032, 'ResultDesc' => 'Request cancelled by user', 'PhoneNumber' => '254712345678'],
			],
		]);
		$this->paymentMapper->method('findById')->willReturn($this->payment(PaymentStatus::FAILED->value, $meta));

		$out = $this->service->forPaymentId(42);
		$this->assertSame('cancelled', $out['diagnostic']['failureCategory']);
		$this->assertSame('email is required', $out['diagnostic']['providerError']['message']);
		$this->assertTrue($out['diagnostic']['hasError']);
		// PhoneNumber is NOT in the allowlist, so it must not leak from the stage
		$this->assertArrayNotHasKey('PhoneNumber', $out['lifecycle']['callback']['fields']);
		$this->assertArrayHasKey('ResultDesc', $out['lifecycle']['callback']['fields']);
	}

	public function testRawPassthroughMasksEveryPhone(): void {
		// Raw tab shows verbatim metadata, but every phone value must be masked —
		// both a phone-named key and the M-Pesa Name/Value callback item.
		$meta = json_encode([
			'executedProvider' => 'daraja',
			'phoneNumber' => '254796336834',
			'providerPayload' => [
				'callback' => [
					'ResultCode' => 0,
					'ResultDesc' => 'The service request is processed successfully.',
					'CallbackMetadata' => ['Item' => [
						['Name' => 'Amount', 'Value' => 5],
						['Name' => 'MpesaReceiptNumber', 'Value' => 'UG3MB9N9OY'],
						['Name' => 'PhoneNumber', 'Value' => 254796336834],
					]],
				],
			],
			'providerError' => null,
		]);
		$this->paymentMapper->method('findById')->willReturn($this->payment(PaymentStatus::PAID->value, $meta));

		$out = $this->service->forPaymentId(42);
		$this->assertIsArray($out['raw']);
		$encoded = json_encode($out['raw']);
		// full phone must NOT appear anywhere in the raw blob
		$this->assertStringNotContainsString('254796336834', $encoded);
		// but the receipt (not PII) survives and is extracted to identifiers
		$this->assertStringContainsString('UG3MB9N9OY', $encoded);
		$this->assertSame('UG3MB9N9OY', $out['identifiers']['mpesaReceipt']);
	}

	public function testDpoIdentifiers(): void {
		$meta = json_encode([
			'preferredProvider' => 'dpo',
			'executedProvider' => 'dpo',
			'providerExecutionState' => 'requires_selection',
			'providerPayload' => [
				'initiation' => ['Result' => '000', 'ResultExplanation' => 'Transaction created', 'TransToken' => 'TKN-1', 'TransRef' => 'R83232194'],
				'charge' => ['StatusCode' => '130', 'ResultExplanation' => 'Success. Request accepted for processing'],
			],
			'providerError' => null,
		]);
		$this->paymentMapper->method('findById')->willReturn($this->payment(PaymentStatus::PAID->value, $meta, 'dpo'));

		$out = $this->service->forPaymentId(42);
		$this->assertSame('TKN-1', $out['identifiers']['dpoTransactionToken']);
		$this->assertSame('R83232194', $out['identifiers']['dpoTransactionRef']);
		$this->assertSame('success', $out['outcome']['reason']);
	}

	public function testFullRowIsUnmaskedSnakeCaseForExport(): void {
		$meta = json_encode([
			'executedProvider' => 'daraja',
			'providerPayload' => ['callback' => ['ResultCode' => 0, 'ResultDesc' => 'ok']],
		]);
		$this->paymentMapper->method('findById')->willReturn($this->payment(PaymentStatus::PAID->value, $meta));

		$out = $this->service->forPaymentId(42);
		$row = $out['row'];

		$this->assertIsArray($row);
		// snake_case DB columns present
		$this->assertArrayHasKey('phone_e164_digits', $row);
		$this->assertArrayHasKey('provider_metadata', $row);
		$this->assertArrayHasKey('created_at', $row);
		$this->assertSame('SIGN_DOCUMENT', $row['product_code']);
		// the export is UNMASKED by design (contrast the masked 'raw' projection)
		$this->assertSame('254712345678', $row['phone_e164_digits']);
	}

	public function testMalformedMetadataDegradesGracefully(): void {
		$this->paymentMapper->method('findById')->willReturn($this->payment(PaymentStatus::PENDING->value, '{not valid json'));

		$out = $this->service->forPaymentId(42);

		$this->assertSame('pending', $out['summary']['status']);
		$this->assertNull($out['lifecycle']['initiation']);
		$this->assertNull($out['diagnostic']['providerError']);
		$this->assertFalse($out['diagnostic']['hasError']);
		$this->assertNull($out['raw']);
	}
}
