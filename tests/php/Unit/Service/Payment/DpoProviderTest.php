<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service\Payment;

use OCA\Libresign\Service\Payment\DpoPaymentService;
use OCA\Libresign\Service\Payment\DpoProvider;
use OCA\Libresign\Service\Payment\DTO\CardPaymentPayloadDTO;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers DpoProvider::initiateCard()'s handling of CardPaymentPayloadDTO::returnUrl
 * -- the absolute, client-facing URL (built by the frontend from
 * window.location.origin) that the customer should land back on if they
 * cancel out of DPO's hosted page. The provider validates it against the
 * same allowed-host policy as redirectUrl and passes it straight through as
 * DPO's BackURL, stripping only the fragment (DPO appends its own query
 * parameters to BackURL, which a fragment would swallow). There is no
 * server-side reconstruction of the origin: the client-facing URL is
 * authoritative, subject to the host allowlist.
 */
final class DpoProviderTest extends TestCase {
	private DpoPaymentService&MockObject $dpo;
	private LoggerInterface&MockObject $logger;
	private IRequest&MockObject $request;
	private DpoProvider $provider;

	public function setUp(): void {
		parent::setUp();

		$this->dpo = $this->createMock(DpoPaymentService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getServerHost')->willReturn('app.example.com');

		$this->provider = new DpoProvider($this->dpo, $this->logger, $this->request);
	}

	private function makePayload(array $overrides = []): CardPaymentPayloadDTO {
		return new CardPaymentPayloadDTO(...array_merge([
			'amount' => 10.0,
			'currency' => 'KES',
			'userId' => 'user-1',
			'email' => 'user@example.com',
			'redirectUrl' => 'https://app.example.com/f/payment/return',
			'returnUrl' => 'https://app.example.com/f/sign/abc-123?retrySign=true',
		], $overrides));
	}

	private function stubCreateTokenCapturing(): \stdClass {
		$capture = new \stdClass();
		$capture->backUrl = 'UNSET';

		$this->dpo->method('createToken')->willReturnCallback(
			function (
				string $userEmail,
				float $amount,
				string $redirectUrl,
				string $currency,
				?string $method = null,
				?string $defaultPayment = null,
				?string $defaultPaymentCountry = null,
				?string $defaultPaymentMno = null,
				?string $backUrl = null,
			) use ($capture): array {
				$capture->backUrl = $backUrl;
				return ['reference' => 'REF1', 'paymentUrl' => 'https://dpo.example/pay?ID=REF1', 'raw' => []];
			}
		);

		return $capture;
	}

	/**
	 * returnUrl is required for card payments (enforced upstream in
	 * PaymentInitiationService too); a caller invoking the provider
	 * directly without one gets a clear, specific error rather than a
	 * misleading "host not allowed" message.
	 */
	public function testThrowsClearErrorWhenReturnUrlMissing(): void {
		$this->dpo->expects($this->never())->method('createToken');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Return URL is required for card payments');

		$this->provider->initiateCard($this->makePayload(['returnUrl' => null]));
	}

	public function testPassesAbsoluteSameOriginReturnUrlAsBackUrl(): void {
		$capture = $this->stubCreateTokenCapturing();

		$this->provider->initiateCard($this->makePayload([
			'returnUrl' => 'https://app.example.com/f/sign/abc-123?retrySign=true',
		]));

		self::assertSame('https://app.example.com/f/sign/abc-123?retrySign=true', $capture->backUrl);
	}

	public function testStripsFragmentFromBackUrlButKeepsQuery(): void {
		$capture = $this->stubCreateTokenCapturing();

		$this->provider->initiateCard($this->makePayload([
			'returnUrl' => 'https://app.example.com/f/sign/abc-123?retrySign=true#top',
		]));

		self::assertSame('https://app.example.com/f/sign/abc-123?retrySign=true', $capture->backUrl);
	}

	/**
	 * The persisted returnUrl (used later for the successful-payment
	 * redirect) is a separate value owned by PaymentInitiationService; this
	 * provider never mutates payload->returnUrl itself, it only derives the
	 * BackURL string it sends to DPO.
	 */
	public function testFragmentStrippingDoesNotMutatePayload(): void {
		$this->stubCreateTokenCapturing();

		$payload = $this->makePayload([
			'returnUrl' => 'https://app.example.com/f/sign/abc-123?retrySign=true#top',
		]);

		$this->provider->initiateCard($payload);

		self::assertSame('https://app.example.com/f/sign/abc-123?retrySign=true#top', $payload->returnUrl);
	}

	public function testRejectsAbsoluteExternalReturnUrl(): void {
		$this->dpo->expects($this->never())->method('createToken');
		$this->expectException(RuntimeException::class);

		$this->provider->initiateCard($this->makePayload([
			'returnUrl' => 'https://evil.example/phish',
		]));
	}

	public function testRejectsProtocolRelativeReturnUrl(): void {
		$this->dpo->expects($this->never())->method('createToken');
		$this->expectException(RuntimeException::class);

		$this->provider->initiateCard($this->makePayload([
			'returnUrl' => '//evil.example/phish',
		]));
	}

	public function testRejectsSubdomainConfusionReturnUrl(): void {
		$this->dpo->expects($this->never())->method('createToken');
		$this->expectException(RuntimeException::class);

		$this->provider->initiateCard($this->makePayload([
			'returnUrl' => 'https://app.example.com.evil.example/foo',
		]));
	}

	public function testRejectsUserinfoConfusionReturnUrl(): void {
		$this->dpo->expects($this->never())->method('createToken');
		$this->expectException(RuntimeException::class);

		$this->provider->initiateCard($this->makePayload([
			'returnUrl' => 'https://app.example.com@evil.example/foo',
		]));
	}

	/**
	 * Malformed values with no extractable host -- including a relative,
	 * backslash-mangled path -- are rejected the same way as any other
	 * value without a valid, allowed host.
	 */
	public function testRejectsMalformedReturnUrlWithNoHost(): void {
		$this->dpo->expects($this->never())->method('createToken');
		$this->expectException(RuntimeException::class);

		$this->provider->initiateCard($this->makePayload([
			'returnUrl' => '/\\evil.example/phish',
		]));
	}

	/**
	 * redirectUrl keeps its own, independent host validation -- confirming
	 * the two checks don't interfere with each other.
	 */
	public function testRejectsExternalRedirectUrlIndependentlyOfReturnUrl(): void {
		$this->dpo->expects($this->never())->method('createToken');
		$this->expectException(RuntimeException::class);

		$this->provider->initiateCard($this->makePayload([
			'redirectUrl' => 'https://evil.example/foo',
		]));
	}
}
