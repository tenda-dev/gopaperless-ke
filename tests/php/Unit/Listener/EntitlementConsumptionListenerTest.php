<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Listener;

use OCA\Libresign\Db\File as FileEntity;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Enum\ProductCode;
use OCA\Libresign\Enum\SettlementSource;
use OCA\Libresign\Events\SignedEvent;
use OCA\Libresign\Listener\EntitlementConsumptionListener;
use OCA\Libresign\Service\IdentifyMethod\IIdentifyMethod;
use OCA\Libresign\Service\Sponsorship\DTO\SignerResolutionDTO;
use OCA\Libresign\Service\Sponsorship\SignerResolverService;
use OCA\Libresign\Service\Sponsorship\SigningSettlementService;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * B3: inert listener (not registered in Application.php yet -- that is B5,
 * together with the reconciliation job). Covers the two fail-safe rules
 * that matter most here: an unresolved signer never reaches settle(), and
 * SignedEvent::getUser() (the file owner) is never used as a settlement
 * identity, whatever the event otherwise carries.
 */
final class EntitlementConsumptionListenerTest extends TestCase {
	private SignerResolverService&MockObject $signerResolver;
	private SigningSettlementService&MockObject $settlementService;
	private LoggerInterface&MockObject $logger;
	private EntitlementConsumptionListener $listener;

	protected function setUp(): void {
		$this->signerResolver = $this->createMock(SignerResolverService::class);
		$this->settlementService = $this->createMock(SigningSettlementService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->listener = new EntitlementConsumptionListener(
			$this->signerResolver,
			$this->settlementService,
			$this->logger,
		);
	}

	private function makeSignedEvent(int $signRequestId): SignedEvent {
		$signRequest = new SignRequest();
		$signRequest->setId($signRequestId);

		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('owner-not-the-signer');

		return new SignedEvent(
			$signRequest,
			new FileEntity(),
			$this->createMock(IIdentifyMethod::class),
			$owner,
			$this->createMock(File::class),
		);
	}

	public function testIgnoresEventsThatAreNotSignedEvent(): void {
		$other = $this->createMock(Event::class);

		$this->signerResolver->expects($this->never())->method('resolve');
		$this->settlementService->expects($this->never())->method('settle');

		$this->listener->handle($other);
	}

	public function testNeverSettlesWhenSignerIsUnresolved(): void {
		$event = $this->makeSignedEvent(42);

		$this->signerResolver
			->method('resolve')
			->with(42)
			->willReturn(SignerResolutionDTO::unresolved('email_ambiguous'));

		$this->settlementService->expects($this->never())->method('settle');

		$this->listener->handle($event);
	}

	public function testSettlesWithTheResolvedSignerNeverTheEventOwner(): void {
		$event = $this->makeSignedEvent(42);

		$this->signerResolver
			->method('resolve')
			->with(42)
			->willReturn(SignerResolutionDTO::resolved('real-signer'));

		$this->settlementService
			->expects($this->once())
			->method('settle')
			->with(
				signRequestId: 42,
				signerUserId: 'real-signer',
				productCode: ProductCode::SIGN_DOCUMENT->value,
				source: SettlementSource::SIGNED_EVENT_LISTENER,
			);

		$this->listener->handle($event);
	}

	public function testSwallowsAndLogsSettlementFailureWithoutPropagating(): void {
		$event = $this->makeSignedEvent(42);

		$this->signerResolver
			->method('resolve')
			->willReturn(SignerResolutionDTO::resolved('real-signer'));

		$this->settlementService
			->method('settle')
			->willThrowException(new \RuntimeException('settlement exploded'));

		$this->logger->expects($this->once())->method('error');

		// No exception propagates: the signature already stands, and the
		// reconciliation job is the backstop for anything missed here.
		$this->listener->handle($event);
	}
}
