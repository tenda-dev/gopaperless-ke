<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\TuandikianeApiController;
use OCA\Libresign\Controller\TuandikianeController;
use OCA\Libresign\Db\TuandikianeDraft;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Service\Payment\LinkPaymentService;
use OCA\Libresign\Service\SecurySignService;
use OCA\Libresign\Service\TuandikianeService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TuandikianeControllerTest extends TestCase {
	private const PASSKEY = ['id' => 'credential', 'response' => ['signature' => 'sig']];

	private TuandikianeService&MockObject $tuandikiane;
	private SecurySignService&MockObject $signa;
	private LinkPaymentService&MockObject $payments;
	private IRequest&MockObject $request;

	protected function setUp(): void {
		$this->tuandikiane = $this->createMock(TuandikianeService::class);
		$this->signa = $this->createMock(SecurySignService::class);
		$this->payments = $this->createMock(LinkPaymentService::class);
		$this->request = $this->createMock(IRequest::class);
	}

	private function controller(): TuandikianeController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new TuandikianeController($this->request, $this->tuandikiane, $this->signa, $this->payments,
			$this->createMock(IURLGenerator::class), $l10n, $this->createMock(LoggerInterface::class));
	}

	/** An open draft moderated by sub-neo; $signs puts the moderator among the signers. */
	private function openDraft(bool $signs = false): TuandikianeDraft {
		$draft = new TuandikianeDraft();
		$draft->setSlots([['key' => 's1', 'phone' => '+254711000001', 'sender' => $signs, 'signRequestId' => 101, 'uid' => 'neo', 'card' => false]]);
		$this->tuandikiane->method('draftForCode')->with('code')->willReturn($draft);
		$this->tuandikiane->method('isOpen')->willReturn(true);
		$this->tuandikiane->method('moderatorSub')->willReturn('sub-neo');
		return $draft;
	}

	public function testSealNeedsTheModeratorsMimiPasskeyWithNoLogin(): void {
		$this->openDraft();
		$this->signa->method('verifyPasskey')->with(self::PASSKEY, 'sub-neo')->willReturn(false);
		$this->tuandikiane->expects($this->never())->method('seal');

		$response = $this->controller()->seal('code', true, self::PASSKEY);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('rejected', $response->getData()['error']);
	}

	public function testSealWithThePasskeySealsWithTheChoiceGiven(): void {
		$draft = $this->openDraft();
		$this->signa->method('verifyPasskey')->with(self::PASSKEY, 'sub-neo')->willReturn(true);
		$this->tuandikiane->expects($this->once())->method('seal')->with($draft, false)->willReturn(null);

		$response = $this->controller()->seal('code', false, self::PASSKEY);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['signUrl' => null], $response->getData());
	}

	public function testAModeratorWhoSignsGoesToTheirCardWithoutAMimiPasskey(): void {
		$this->openDraft(signs: true);
		$this->signa->expects($this->never())->method('verifyPasskey');
		$this->tuandikiane->method('seal')->willReturn('https://gp.test/apps/libresign/p/whatsapp/sr.mac');

		$this->assertSame(['signUrl' => 'https://gp.test/apps/libresign/p/whatsapp/sr.mac'], $this->controller()->seal('code')->getData());
	}

	public function testSealWhileMimiIsDownSealsNothing(): void {
		$this->openDraft();
		$this->signa->method('verifyPasskey')->willThrowException(new \RuntimeException('MIMI is unreachable.', 503));
		$this->tuandikiane->expects($this->never())->method('seal');

		$this->assertSame(503, $this->controller()->seal('code', true, self::PASSKEY)->getStatus());
	}

	public function testSealOnAnExpiredOrUsedCodeAsksForNoPasskey(): void {
		$this->tuandikiane->method('draftForCode')->willReturn(null);
		$this->signa->expects($this->never())->method('verifyPasskey');

		$this->assertSame(410, $this->controller()->seal('code', true, self::PASSKEY)->getStatus());
	}

	public function testSealShortOfCreditsSaysHowManyToBuy(): void {
		$this->openDraft(signs: true);
		$this->tuandikiane->method('seal')->willThrowException(new LibresignException('Buy the signatures first.', 402));
		$this->tuandikiane->method('shortfall')->willReturn(2);

		$response = $this->controller()->seal('code');

		$this->assertSame(402, $response->getStatus());
		$this->assertSame(['error' => 'payment_required', 'short' => 2], $response->getData());
	}

	public function testPayBuysOnlyTheShortfallAsTheModeratorOnTheirNumber(): void {
		$this->openDraft(signs: true);
		$neo = $this->createMock(IUser::class);
		$this->tuandikiane->method('moderator')->willReturn($neo);
		$this->tuandikiane->method('shortfall')->willReturn(2);
		$this->payments->expects($this->once())->method('start')->with($neo, 2, 'mobile', '+254711000001')
			->willReturn(['reference' => 'REF1', 'flow' => 'async', 'redirectUrl' => null]);

		$this->assertSame('REF1', $this->controller()->pay('code')->getData()['reference']);
	}

	public function testPayWithEnoughCreditsStartsNothing(): void {
		$this->openDraft();
		$this->tuandikiane->method('shortfall')->willReturn(0);
		$this->payments->expects($this->never())->method('start');

		$this->assertSame(['status' => 'SUCCESS'], $this->controller()->pay('code')->getData());
	}

	private function api(string $key): TuandikianeApiController {
		$this->request->method('getHeader')->willReturnMap([['X-Tuandikiane-Key', $key]]);
		$this->request->method('getParams')->willReturn(['reference' => 'DOC-1']);
		return new TuandikianeApiController($this->request, $this->tuandikiane, $this->createMock(LoggerInterface::class));
	}

	public function testTheDraftsCallWithTheWrongKeyIsThrottledAndMakesNothing(): void {
		$this->tuandikiane->method('checkKey')->willThrowException(new LibresignException('Wrong key.', 401));
		$this->tuandikiane->expects($this->never())->method('createDraft');

		$response = $this->api('nope')->create();

		$this->assertSame(401, $response->getStatus());
		$this->assertTrue($response->isThrottled());
	}

	public function testTheDraftsCallWithNoKeySetIsUnavailable(): void {
		$this->tuandikiane->method('checkKey')->willThrowException(new LibresignException('Not set up.', 503));

		$this->assertSame(503, $this->api('key-123')->create()->getStatus());
	}

	public function testTheDraftsCallWithTheKeyAnswersWithTheDraft(): void {
		$this->tuandikiane->expects($this->once())->method('checkKey')->with('key-123');
		$draft = ['draft_uuid' => 'uuid', 'review_url' => 'https://gp.test/apps/libresign/p/t/review/uuid.mac', 'expires_at' => '2026-11-07T10:00:00+00:00'];
		$this->tuandikiane->method('createDraft')->with(['reference' => 'DOC-1'])->willReturn($draft);

		$response = $this->api('key-123')->create();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($draft, $response->getData());
	}

	public function testABadDraftBodyIsAnUnprocessableEntity(): void {
		$this->tuandikiane->method('createDraft')->willThrowException(new LibresignException('boxes[0].signer must be one of the signers\' keys', 422));

		$response = $this->api('key-123')->create();

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('boxes[0].signer must be one of the signers\' keys', $response->getData()['message']);
	}
}
