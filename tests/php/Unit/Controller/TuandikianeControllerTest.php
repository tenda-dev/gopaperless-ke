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
use OCA\Libresign\Service\SecurySignService;
use OCA\Libresign\Service\TuandikianeService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TuandikianeControllerTest extends TestCase {
	private const PASSKEY = ['id' => 'credential', 'response' => ['signature' => 'sig']];

	private TuandikianeService&MockObject $tuandikiane;
	private SecurySignService&MockObject $signa;
	private IUserSession&MockObject $session;
	private IRequest&MockObject $request;

	protected function setUp(): void {
		$this->tuandikiane = $this->createMock(TuandikianeService::class);
		$this->signa = $this->createMock(SecurySignService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->request = $this->createMock(IRequest::class);
	}

	private function controller(): TuandikianeController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new TuandikianeController($this->request, $this->tuandikiane, $this->signa, $this->session,
			$this->createMock(IUserManager::class), $this->createMock(IURLGenerator::class), $l10n, $this->createMock(LoggerInterface::class));
	}

	private function signedInWithOpenDraft(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('neo');
		$this->session->method('getUser')->willReturn($user);
		$this->tuandikiane->method('draftForCode')->with('code')->willReturn(new TuandikianeDraft());
		return $user;
	}

	public function testSendNeedsTheMimiPasskeyOfThisAccount(): void {
		$this->signedInWithOpenDraft();
		$this->signa->method('verifyPasskey')->with(self::PASSKEY)->willReturn(false);
		$this->tuandikiane->expects($this->never())->method('send');

		$response = $this->controller()->send('code', self::PASSKEY);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('rejected', $response->getData()['error']);
	}

	public function testSendWithThePasskeySendsAndOffersTheSendersCard(): void {
		$user = $this->signedInWithOpenDraft();
		$this->signa->method('verifyPasskey')->with(self::PASSKEY)->willReturn(true);
		$this->tuandikiane->expects($this->once())->method('send')->with($this->isInstanceOf(TuandikianeDraft::class), $user)
			->willReturn('https://gp.test/apps/libresign/p/whatsapp/sr.mac');

		$response = $this->controller()->send('code', self::PASSKEY);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['signUrl' => 'https://gp.test/apps/libresign/p/whatsapp/sr.mac'], $response->getData());
	}

	public function testSendWhileMimiIsDownSendsNothing(): void {
		$this->signedInWithOpenDraft();
		$this->signa->method('verifyPasskey')->willThrowException(new \RuntimeException('MIMI is unreachable.', 503));
		$this->tuandikiane->expects($this->never())->method('send');

		$this->assertSame(503, $this->controller()->send('code', self::PASSKEY)->getStatus());
	}

	public function testSendOnAnExpiredOrUsedCodeAsksForNoPasskey(): void {
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->tuandikiane->method('draftForCode')->willReturn(null);
		$this->signa->expects($this->never())->method('verifyPasskey');

		$this->assertSame(410, $this->controller()->send('code', self::PASSKEY)->getStatus());
	}

	public function testSendThatLostARaceSaysSo(): void {
		$this->signedInWithOpenDraft();
		$this->signa->method('verifyPasskey')->willReturn(true);
		$this->tuandikiane->method('send')->willThrowException(new LibresignException('This link has expired.', 410));

		$this->assertSame(410, $this->controller()->send('code', self::PASSKEY)->getStatus());
	}

	public function testTakingASlotNeedsThePasskeyToo(): void {
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->tuandikiane->method('unboundSlot')->willReturn(['draft' => new TuandikianeDraft(), 'slot' => [], 'signRequest' => null]);
		$this->signa->method('verifyPasskey')->willReturn(false);
		$this->tuandikiane->expects($this->never())->method('bindCard');

		$this->assertSame(403, $this->controller()->bind('card-code', self::PASSKEY)->getStatus());
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
