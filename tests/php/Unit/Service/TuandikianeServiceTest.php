<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Db\File as FileEntity;
use OCA\Libresign\Db\FileElementMapper;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\IdentifyMethod;
use OCA\Libresign\Db\IdentifyMethodMapper;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Db\TuandikianeDraft;
use OCA\Libresign\Db\TuandikianeDraftMapper;
use OCA\Libresign\Enum\FileStatus;
use OCA\Libresign\Enum\SignRequestStatus;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Helper\ValidateHelper;
use OCA\Libresign\Service\FileElementService;
use OCA\Libresign\Service\FolderService;
use OCA\Libresign\Service\IdentifyMethodService;
use OCA\Libresign\Service\Payment\LinkPaymentService;
use OCA\Libresign\Service\RequestSignatureService;
use OCA\Libresign\Service\SecurySignService;
use OCA\Libresign\Service\TuandikianeClient;
use OCA\Libresign\Service\TuandikianeService;
use OCA\Libresign\Service\WhatsAppService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Seal & Deliver on the GoPaperless side: the draft is the moderator's own,
 * signers are MIMI accounts or joiners' slots, sealing spends the moderator's
 * credits on the signers, and a joiner's account takes their slot later.
 */
final class TuandikianeServiceTest extends TestCase {
	private const DRAFT = '7d1f2c3a-4b5e-4f60-8a9b-0c1d2e3f4a5b';
	private const NOW = '2026-11-06T10:00:00+00:00';

	private array $settings = ['tuandikiane_api_key' => 'key-123'];
	private IDBConnection&MockObject $db;
	private IUserManager&MockObject $users;
	private TuandikianeDraftMapper&MockObject $drafts;
	private RequestSignatureService&MockObject $requestSignature;
	private FileElementService&MockObject $fileElementService;
	private FileElementMapper&MockObject $fileElements;
	private FileMapper&MockObject $files;
	private SignRequestMapper&MockObject $signRequests;
	private IdentifyMethodMapper&MockObject $identifyMethods;
	private IdentifyMethodService&MockObject $identifyMethodService;
	private FolderService&MockObject $folders;
	private ValidateHelper&MockObject $validateHelper;
	private WhatsAppService&MockObject $whatsApp;
	private SecurySignService&MockObject $signa;
	private LinkPaymentService&MockObject $payments;
	private TuandikianeClient&MockObject $client;
	private IURLGenerator&MockObject $urls;
	/** @var array<string, IUser> uid => account, also what MIMI's subs name ("sub-<uid>") */
	private array $accounts = [];
	/** @var list<array{string, ?array}> every draft write, as [status, slots] */
	private array $stored = [];

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('get')->willReturnCallback(fn (string $uid): ?IUser => $this->accounts[$uid] ?? null);
		$this->drafts = $this->createMock(TuandikianeDraftMapper::class);
		$this->requestSignature = $this->createMock(RequestSignatureService::class);
		$this->fileElementService = $this->createMock(FileElementService::class);
		$this->fileElements = $this->createMock(FileElementMapper::class);
		$this->files = $this->createMock(FileMapper::class);
		$this->signRequests = $this->createMock(SignRequestMapper::class);
		$this->identifyMethods = $this->createMock(IdentifyMethodMapper::class);
		$this->identifyMethodService = $this->createMock(IdentifyMethodService::class);
		$this->folders = $this->createMock(FolderService::class);
		$this->validateHelper = $this->createMock(ValidateHelper::class);
		$this->whatsApp = $this->createMock(WhatsAppService::class);
		$this->whatsApp->method('subFor')->willReturnCallback(static fn (IUser $user): string => 'sub-' . $user->getUID());
		$this->whatsApp->method('linkCode')->willReturnCallback(static fn (SignRequest $signRequest): string => $signRequest->getUuid() . '.mac');
		$this->signa = $this->createMock(SecurySignService::class);
		$this->signa->method('accountFor')->willReturnCallback(fn (string $sub): ?IUser => $this->accounts[substr($sub, 4)] ?? null);
		$this->payments = $this->createMock(LinkPaymentService::class);
		$this->client = $this->createMock(TuandikianeClient::class);
		$this->urls = $this->createMock(IURLGenerator::class);
		$this->urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params): string => 'https://gp.test/' . $route . '/' . $params['code'],
		);
		$this->drafts->method('update')->willReturnCallback(function (TuandikianeDraft $draft): TuandikianeDraft {
			$this->stored[] = [$draft->getStatus(), $draft->getSlots()];
			return $draft;
		});
		$this->identifyMethods->method('update')->willReturnArgument(0);
		foreach (['neo', 'ali'] as $uid) {
			$this->accounts[$uid] = $this->user($uid);
		}
	}

	private function service(): TuandikianeService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->settings[$key] ?? $default,
		);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnMap([['secret', '', 'instance-secret']]);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime(self::NOW));
		return new TuandikianeService($appConfig, $config, $this->db, $this->users, $time, $this->drafts,
			$this->requestSignature, $this->fileElementService, $this->fileElements, $this->files, $this->signRequests,
			$this->identifyMethods, $this->identifyMethodService, $this->folders, $this->validateHelper, $this->whatsApp,
			$this->signa, $this->payments, $this->client, $this->urls, $this->createMock(LoggerInterface::class));
	}

	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn(ucfirst($uid));
		return $user;
	}

	/** Neo moderates and signs; Ali has MIMI; Wanjiru doesn't yet. */
	private static function input(array $override = []): array {
		return $override + [
			'reference' => 'DOC-9EB7DE75CCD8',
			'name' => 'Loan agreement between Neo and Ali',
			'file' => ['base64' => 'JVBERi0xLjQK'],
			'moderator' => ['sub' => 'sub-neo', 'phone' => '+254711000001'],
			'signers' => [
				['key' => 's1', 'display_name' => 'Neo Kamau', 'phone' => '+254711000001', 'email' => null, 'is_sender' => true, 'sub' => 'sub-neo'],
				['key' => 's2', 'display_name' => '', 'phone' => '+254722000002', 'email' => null, 'is_sender' => false, 'sub' => 'sub-ali'],
				['key' => 's3', 'display_name' => 'Wanjiru', 'phone' => '+254733000003', 'email' => null, 'is_sender' => false, 'sub' => null],
			],
			'boxes' => [
				['signer' => 's1', 'page' => 1, 'left' => 72, 'top' => 640, 'width' => 180, 'height' => 48],
				['signer' => 's3', 'page' => 2, 'left' => 320, 'top' => 640, 'width' => 180, 'height' => 48],
			],
		];
	}

	private static function file(string $owner = 'neo'): FileEntity {
		$file = new FileEntity();
		$file->setId(42);
		$file->setUuid('f1e2d3c4-0000-4000-8000-000000000042');
		$file->setUserId($owner);
		$file->setNodeId(900);
		$file->setName('Loan agreement between Neo and Ali');
		$file->setMetadata(['d' => [['w' => 595, 'h' => 842], ['w' => 595, 'h' => 842]]]);
		return $file;
	}

	private static function signRequest(int $id, ?\DateTime $signed = null, SignRequestStatus $status = SignRequestStatus::ABLE_TO_SIGN): SignRequest {
		$signRequest = new SignRequest();
		$signRequest->setId($id);
		$signRequest->setUuid(sprintf('00000000-0000-4000-8000-%012d', $id));
		$signRequest->setFileId(42);
		$signRequest->setDisplayName(['101' => 'Neo Kamau', '102' => 'Ali', '103' => 'Wanjiru'][(string)$id] ?? 'Signer');
		$signRequest->setSigningOrder(1);
		$signRequest->setStatus($status->value);
		if ($signed !== null) {
			$signRequest->setSigned($signed);
		}
		return $signRequest;
	}

	private static function method(int $signRequestId, string $key, string $value): IdentifyMethod {
		$method = new IdentifyMethod();
		$method->setSignRequestId($signRequestId);
		$method->setIdentifierKey($key);
		$method->setIdentifierValue($value);
		return $method;
	}

	private static function draft(string $status = TuandikianeDraft::STATUS_DRAFT, string $expires = '2026-11-07T10:00:00+00:00', bool $moderatorSigns = true, ?string $s3 = null): TuandikianeDraft {
		$draft = new TuandikianeDraft();
		$draft->setId(5);
		$draft->setDraftUuid(self::DRAFT);
		$draft->setReference('DOC-9EB7DE75CCD8');
		$draft->setFileId(42);
		$draft->setStatus($status);
		$draft->setExpiresAt(new \DateTime($expires));
		$draft->setSlots([
			['key' => 's1', 'phone' => '+254711000001', 'sender' => $moderatorSigns, 'named' => true, 'signRequestId' => 101, 'uid' => 'neo', 'card' => false],
			['key' => 's2', 'phone' => '+254722000002', 'sender' => false, 'named' => false, 'signRequestId' => 102, 'uid' => 'ali', 'card' => false],
			['key' => 's3', 'phone' => '+254733000003', 'sender' => false, 'named' => true, 'signRequestId' => 103, 'uid' => $s3, 'card' => false],
		]);
		return $draft;
	}

	public static function keys(): array {
		return [
			'no key set' => ['', 'key-123', 503],
			'no key sent' => ['key-123', '', 401],
			'the wrong key' => ['key-123', 'key-124', 401],
			'the key' => ['key-123', 'key-123', null],
		];
	}

	#[DataProvider('keys')]
	public function testTheKeyMustMatchTheSetting(string $setting, string $given, ?int $status): void {
		$this->settings['tuandikiane_api_key'] = $setting;
		try {
			$this->service()->checkKey($given);
			$this->assertNull($status);
		} catch (LibresignException $e) {
			$this->assertSame($status, $e->getCode());
		}
	}

	/** Arranges a draft's creation. $saved gets what save() was given, $elements the boxes saved. */
	private function creating(?array &$saved, array &$elements): void {
		$this->drafts->method('findByReference')->willReturn(null);
		$this->drafts->method('insert')->willReturnArgument(0);
		$this->requestSignature->method('save')->willReturnCallback(static function (array $data) use (&$saved): FileEntity {
			$saved = $data;
			return self::file();
		});
		$this->signRequests->method('getByIdentifierAndFileId')->willReturnCallback(
			static fn (string $key, string $value): SignRequest => self::signRequest(match (true) {
				$value === 'neo' => 101,
				$value === 'ali' => 102,
				default => 103,
			}),
		);
		$this->fileElementService->method('saveVisibleElement')->willReturnCallback(static function (array $element) use (&$elements) {
			$elements[] = $element;
			return new \OCA\Libresign\Db\FileElement();
		});
	}

	public function testTheDraftIsTheModeratorsOwnWithAccountsForSignersWhoHaveMimi(): void {
		$saved = null;
		$elements = [];
		$this->creating($saved, $elements);

		$result = $this->service()->createDraft(self::input());

		$this->assertMatchesRegularExpression('#^https://gp\.test/libresign\.tuandikiane\.review/' . $result['draft_uuid'] . '\.[A-Za-z0-9_-]{22}$#', $result['review_url']);
		$this->assertSame(FileStatus::DRAFT->value, $saved['status']);
		$this->assertSame($this->accounts['neo'], $saved['userManager']);
		$this->assertSame('tuandikiane-' . $result['draft_uuid'], $saved['settings']['folderName']);
		$this->assertSame([['method' => 'account', 'value' => 'neo']], $saved['signers'][0]['identifyMethods']);
		$this->assertSame([['method' => 'account', 'value' => 'ali']], $saved['signers'][1]['identifyMethods']);
		$this->assertSame('tuandikiane-' . $result['draft_uuid'] . '-s3@placeholder.securesign.local', $saved['signers'][2]['identifyMethods'][0]['value']);
		// An unnamed signer shows as their number, never their account's name; nobody waits for anyone.
		$this->assertSame(['Neo Kamau', '+254722000002', 'Wanjiru'], array_column($saved['signers'], 'displayName'));
		$this->assertSame([1, 1, 1], array_column($saved['signers'], 'signingOrder'));
		$this->assertSame([0, 0, 0], array_column($saved['signers'], 'notify'));

		[, $slots] = end($this->stored);
		$this->assertSame(['neo', 'ali', null], array_column($slots, 'uid'));
		$this->assertSame([101, 103], array_column($elements, 'signRequestId'));
	}

	public function testTwoNumbersOnOneAccountLeaveTheSecondAJoinersSlot(): void {
		$saved = null;
		$elements = [];
		$this->creating($saved, $elements);
		$input = self::input();
		$input['signers'][2]['sub'] = 'sub-ali';

		$this->service()->createDraft($input);

		$this->assertSame('email', $saved['signers'][2]['identifyMethods'][0]['method']);
	}

	public function testAModeratorMimiDoesNotKnowIsRefusedAndAnOutageMakesNothing(): void {
		$this->drafts->method('findByReference')->willReturn(null);
		$this->drafts->expects($this->never())->method('insert');
		try {
			$this->service()->createDraft(self::input(['moderator' => ['sub' => 'sub-nobody']]));
			$this->fail('Accepted');
		} catch (LibresignException $e) {
			$this->assertSame(422, $e->getCode());
		}

		$down = $this->createMock(SecurySignService::class);
		$down->method('accountFor')->willThrowException(new \RuntimeException('MIMI is unreachable.', 503));
		$this->signa = $down;
		try {
			$this->service()->createDraft(self::input());
			$this->fail('Accepted');
		} catch (LibresignException $e) {
			$this->assertSame(503, $e->getCode());
		}
	}

	public function testTheSameReferenceGetsTheSameDraftBack(): void {
		$this->drafts->method('findByReference')->willReturn(self::draft());
		$this->drafts->expects($this->never())->method('insert');
		$this->requestSignature->expects($this->never())->method('save');

		$result = $this->service()->createDraft(self::input());

		$this->assertSame(self::DRAFT, $result['draft_uuid']);
	}

	public function testTheSameReferenceAfterItsDraftExpiredUnsealedMakesANewOne(): void {
		$this->drafts->method('findByReference')->willReturn(self::draft(expires: '2026-11-06T09:00:00+00:00'));
		$this->files->method('getById')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('gone'));
		$this->drafts->expects($this->once())->method('delete');
		$this->drafts->expects($this->once())->method('insert')->willThrowException(new \RuntimeException('stop here'));

		$this->expectExceptionMessage('stop here');
		$this->service()->createDraft(self::input());
	}

	public static function badBodies(): array {
		$two = self::input()['signers'];
		$two[1]['is_sender'] = true;
		return [
			'no reference' => [['reference' => ' ']],
			'no moderator' => [['moderator' => ['phone' => '+254711000001']]],
			'two senders' => [['signers' => $two]],
			'an empty email' => [['signers' => [['key' => 's1', 'display_name' => 'Neo', 'phone' => '+254711000001', 'email' => '', 'is_sender' => true]]]],
			'no display name at all' => [['signers' => [['key' => 's1', 'phone' => '+254711000001', 'email' => null, 'is_sender' => true]]]],
			'a local number' => [['signers' => [['key' => 's1', 'display_name' => 'Neo', 'phone' => '0711000001', 'email' => null, 'is_sender' => true]]]],
			'a signer outside Kenya' => [['signers' => [['key' => 's1', 'display_name' => 'Neo', 'phone' => '+254711000001', 'email' => null, 'is_sender' => true], ['key' => 's2', 'display_name' => 'Ali', 'phone' => '+447700900123', 'email' => null, 'is_sender' => false]]]],
			'a box for no one' => [['boxes' => [['signer' => 's9', 'page' => 1, 'left' => 0, 'top' => 0, 'width' => 10, 'height' => 10]]]],
			'a box in fractions' => [['boxes' => [['signer' => 's1', 'page' => 1, 'left' => 7.5, 'top' => 0, 'width' => 10, 'height' => 10]]]],
		];
	}

	#[DataProvider('badBodies')]
	public function testABodyThatBreaksTheContractIsRefusedBeforeAnythingIsMade(array $override): void {
		$this->drafts->expects($this->never())->method('insert');
		try {
			$this->service()->createDraft(self::input($override));
			$this->fail('Accepted');
		} catch (LibresignException $e) {
			$this->assertSame(422, $e->getCode());
		}
	}

	public function testAForgedReviewCodeIsNotLookedUp(): void {
		$this->drafts->expects($this->never())->method('findByDraftUuid');
		$service = $this->service();
		$code = $service->reviewCode(self::DRAFT);

		$this->assertNull($service->draftForCode(self::DRAFT . '.' . str_repeat('A', 22)));
		$this->assertNull($service->draftForCode(self::DRAFT));
		$this->assertNull($service->draftForCode('8d1f2c3a-4b5e-4f60-8a9b-0c1d2e3f4a5b' . substr($code, 36)));
	}

	/** A seal's surroundings: the file, its three sign requests and their identities. */
	private function sealing(TuandikianeDraft $locked, ?array &$published): void {
		$file = self::file();
		$this->files->method('getById')->willReturn($file);
		$this->drafts->method('findByDraftUuidForUpdate')->willReturn($locked);
		$methods = [101 => 'neo', 102 => 'ali', 103 => 'tuandikiane-' . self::DRAFT . '-s3@placeholder.securesign.local'];
		$this->identifyMethods->method('getIdentifyMethodsFromSignRequestId')->willReturnCallback(
			static fn (int $id): array => [self::method($id, $id === 103 ? 'email' : 'account', $methods[$id])],
		);
		$this->signRequests->method('getByFileId')->willReturn([self::signRequest(101), self::signRequest(102), self::signRequest(103)]);
		$this->signRequests->method('getById')->willReturnCallback(static fn (int $id): SignRequest => self::signRequest($id));
		$this->requestSignature->method('save')->willReturnCallback(static function (array $data) use (&$published, $file): FileEntity {
			$published = $data;
			return $file;
		});
		$this->payments->method('on')->willReturn(true);
		$this->payments->method('credits')->willReturn(3);
	}

	public function testAModeratorWhoDoesNotSignDeliversToReadySignersAndInvitesTheRest(): void {
		$published = null;
		$this->sealing(self::draft(moderatorSigns: false), $published);
		$cards = [];
		$this->whatsApp->method('sendTo')->willReturnCallback(static function (FileEntity $file, SignRequest $signRequest, IUser $sender, string $sub, string $to) use (&$cards): array {
			$cards[] = [$signRequest->getId(), $sub, $to, $sender->getUID()];
			return ['status' => 'sent', 'error' => null, 'availableAgainAt' => null];
		});
		$events = [];
		$this->client->method('event')->willReturnCallback(static function (string $draft, string $event, ?string $signer = null, array $extra = []) use (&$events): void {
			$events[] = [$event, $extra];
		});
		$this->db->expects($this->once())->method('commit');

		$this->assertNull($this->service()->seal(self::draft(moderatorSigns: false), true));

		$this->assertSame(FileStatus::ABLE_TO_SIGN->value, $published['status']);
		$this->assertSame($this->accounts['neo'], $published['userManager']);
		$this->assertSame([['type' => 'requester'], ['type' => 'requester'], ['type' => 'requester']], array_column($published['signers'], 'sponsorship'));
		$this->assertSame(TuandikianeDraft::STATUS_SENT, $this->stored[0][0]);
		$this->assertSame([[101, 'sub-neo', '+254711000001', 'neo'], [102, 'sub-ali', '+254722000002', 'neo']], $cards);
		$this->assertCount(1, $events);
		[$event, $extra] = $events[0];
		$this->assertSame('sent', $event);
		$this->assertSame('s3', $extra['invites'][0]['signer']);
		$this->assertSame('+254733000003', $extra['invites'][0]['phone']);
		$this->assertMatchesRegularExpression('#^https://gp\.test/libresign\.tuandikiane\.join/' . self::DRAFT . '\.s3\.[A-Za-z0-9_-]{22}$#', $extra['invites'][0]['url']);
	}

	public function testAModeratorWhoSignsSealsBySigningAndNobodyHearsUntilThen(): void {
		$published = null;
		$this->sealing(self::draft(), $published);
		$this->whatsApp->expects($this->never())->method('sendTo');
		$this->client->expects($this->never())->method('event');

		$url = $this->service()->seal(self::draft(), false);

		$this->assertSame('https://gp.test/libresign.whatsAppSign.page/00000000-0000-4000-8000-000000000101.mac', $url);
		$this->assertSame(TuandikianeDraft::STATUS_SEALED, $this->stored[0][0]);
		$this->assertSame([], array_filter(array_column($published['signers'], 'sponsorship')), 'each pays their own');
	}

	public function testCreditsThatFallShortStopTheSeal(): void {
		$published = null;
		$this->sealing(self::draft(), $published);
		$short = $this->createMock(LinkPaymentService::class);
		$short->method('on')->willReturn(true);
		$short->method('credits')->willReturn(1);
		$this->payments = $short;
		$this->requestSignature->expects($this->never())->method('save');

		$this->assertSame(2, $this->service()->shortfall(self::draft(), true));
		$this->assertSame(0, $this->service()->shortfall(self::draft(), false));
		try {
			$this->service()->seal(self::draft(), true);
			$this->fail('Sealed without the credits');
		} catch (LibresignException $e) {
			$this->assertSame(402, $e->getCode());
		}
	}

	public function testSealingTwiceIsGone(): void {
		$published = null;
		$this->sealing(self::draft(TuandikianeDraft::STATUS_SENT), $published);
		$this->db->expects($this->once())->method('rollBack');
		$this->requestSignature->expects($this->never())->method('save');

		try {
			$this->service()->seal(self::draft(), true);
			$this->fail('Sealed twice');
		} catch (LibresignException $e) {
			$this->assertSame(410, $e->getCode());
		}
	}

	public function testAJoinerWhoPaidMimiTakesTheirSlotAndGetsTheirCard(): void {
		$this->accounts['wanjiru'] = $this->user('wanjiru');
		$draft = self::draft(TuandikianeDraft::STATUS_SENT);
		$this->drafts->method('findByDraftUuid')->willReturn($draft);
		$this->drafts->method('findByDraftUuidForUpdate')->willReturn($draft);
		$this->files->method('getById')->willReturn(self::file());
		$method = self::method(103, 'email', 'tuandikiane-x-s3@placeholder.securesign.local');
		$this->identifyMethods->method('getIdentifyMethodsFromSignRequestId')->with(103)->willReturn([$method]);
		$this->signRequests->method('getById')->willReturnCallback(static fn (int $id): SignRequest => self::signRequest($id));
		$cards = [];
		$this->whatsApp->method('sendTo')->willReturnCallback(static function ($file, SignRequest $signRequest) use (&$cards): array {
			$cards[] = $signRequest->getId();
			return ['status' => 'sent', 'error' => null, 'availableAgainAt' => null];
		});

		$this->assertSame(['s3'], $this->service()->join(self::DRAFT, '0733 000 003', 'sub-wanjiru'));

		$this->assertSame(['account', 'wanjiru'], [$method->getIdentifierKey(), $method->getIdentifierValue()]);
		$this->assertContains(103, $cards);
	}

	public function testAJoinRepeatedOrForAnotherNumberChangesNothing(): void {
		$this->accounts['wanjiru'] = $this->user('wanjiru');
		$draft = self::draft(TuandikianeDraft::STATUS_SENT, s3: 'wanjiru');
		$this->drafts->method('findByDraftUuid')->willReturn($draft);
		$this->drafts->method('findByDraftUuidForUpdate')->willReturn($draft);
		$this->identifyMethods->expects($this->never())->method('update');

		$this->assertSame(['s3'], $this->service()->join(self::DRAFT, '+254733000003', 'sub-wanjiru'));
		$this->assertSame([], $this->service()->join(self::DRAFT, '+254799000009', 'sub-wanjiru'));
	}

	public function testAJoinForAnUnknownDraftOrAccountIsRefused(): void {
		$this->drafts->method('findByDraftUuid')->willReturnOnConsecutiveCalls(null, self::draft());
		foreach ([404 => 'sub-wanjiru', 422 => 'sub-nobody'] as $status => $sub) {
			try {
				$this->service()->join(self::DRAFT, '+254733000003', $sub);
				$this->fail('Joined');
			} catch (LibresignException $e) {
				$this->assertSame($status, $e->getCode());
			}
		}
	}

	public function testTheInviteCodeNamesItsSlotOnceSealed(): void {
		$service = $this->service();
		$url = $service->joinUrl(self::draft(), 's3');
		$code = substr($url, strrpos($url, '/') + 1);
		$this->drafts->method('findByDraftUuid')->willReturnOnConsecutiveCalls(self::draft(TuandikianeDraft::STATUS_SENT), self::draft());

		$this->assertSame('s3', $service->slotForJoinCode($code)['slot']['key']);
		$this->assertNull($service->slotForJoinCode($code), 'not sealed yet');
		$this->assertNull($service->slotForJoinCode(self::DRAFT . '.s2.' . substr($code, -22)), 'another slot');
	}

	public function testTheModeratorsSignatureDeliversASealedDraft(): void {
		$this->drafts->method('findByFileId')->willReturn(self::draft(TuandikianeDraft::STATUS_SEALED));
		$this->files->method('getById')->willReturn(self::file());
		$this->signRequests->method('getByFileId')->willReturn([self::signRequest(101, new \DateTime(self::NOW)), self::signRequest(102), self::signRequest(103)]);
		$this->signRequests->method('getById')->willReturnCallback(
			static fn (int $id): SignRequest => self::signRequest($id, $id === 101 ? new \DateTime(self::NOW) : null),
		);
		$cards = [];
		$this->whatsApp->method('sendTo')->willReturnCallback(static function ($file, SignRequest $signRequest) use (&$cards): array {
			$cards[] = $signRequest->getId();
			return ['status' => 'sent', 'error' => null, 'availableAgainAt' => null];
		});
		$events = [];
		$this->client->method('event')->willReturnCallback(static function (string $draft, string $event, ?string $signer = null) use (&$events): void {
			$events[] = [$event, $signer];
		});

		$this->service()->signed(self::file(), self::signRequest(101, new \DateTime(self::NOW)));

		$this->assertSame(TuandikianeDraft::STATUS_SENT, $this->stored[0][0]);
		$this->assertSame([102], $cards, 'the moderator signed already, the joiner has no account');
		$this->assertSame([['sent', null], ['signed', 's1']], $events);
	}

	public function testEachSignatureIsReportedAndTheLastCompletesWithTheSignedPdf(): void {
		$this->drafts->method('findByFileId')->with(42)->willReturn(self::draft(TuandikianeDraft::STATUS_SENT, s3: 'wanjiru'));
		$signed = new \DateTime(self::NOW);
		$this->signRequests->method('getByFileId')->willReturn([self::signRequest(101, $signed), self::signRequest(102, $signed), self::signRequest(103, $signed)]);
		$pdf = $this->createMock(File::class);
		$pdf->method('getContent')->willReturn('%PDF-signed');
		$this->folders->method('getFileByNodeId')->willReturn($pdf);
		$events = [];
		$this->client->method('event')->willReturnCallback(static function (string $draft, string $event, ?string $signer = null, array $extra = []) use (&$events): void {
			$events[] = [$event, $signer, $extra];
		});

		$this->service()->signed(self::file(), self::signRequest(103, $signed));

		$this->assertSame(['signed', 's3', []], $events[0]);
		$this->assertSame('completed', $events[1][0]);
		$this->assertSame(['name' => 'Loan agreement between Neo and Ali', 'pdf_base64' => base64_encode('%PDF-signed')], $events[1][2]['document']);
	}

	public function testASignatureOnAnyOtherDocumentIsLeftAlone(): void {
		$this->drafts->method('findByFileId')->willReturn(null);
		$this->client->expects($this->never())->method('event');

		$this->service()->signed(self::file(), self::signRequest(101));
	}

	public function testTheModeratorSeesWhatTheyWroteOrTheNumberAndNothingAboutMimi(): void {
		$this->files->method('getById')->willReturn(self::file());
		$unnamed = self::signRequest(102);
		$unnamed->setDisplayName('+254722000002');
		$this->signRequests->method('getByFileId')->willReturn([self::signRequest(101), $unnamed, self::signRequest(103)]);
		$this->fileElements->method('getByFileId')->willReturn([]);

		$review = $this->service()->review(self::draft());

		// Ali has MIMI and Wanjiru doesn't: the moderator can't tell.
		$this->assertSame([
			['name' => 'Neo Kamau', 'sender' => true],
			['name' => '+254722000002', 'sender' => false],
			['name' => 'Wanjiru', 'sender' => false],
		], $review['signers']);
	}

	public function testAnUnnamedSignerSignsUnderTheirOwnNameAndShowsItOnceSigned(): void {
		$this->drafts->method('findByFileId')->willReturn(self::draft(TuandikianeDraft::STATUS_SENT));
		$this->signRequests->method('getByFileId')->willReturn([self::signRequest(101), self::signRequest(102), self::signRequest(103)]);
		$unnamed = self::signRequest(102);
		$unnamed->setDisplayName('+254722000002');
		$service = $this->service();

		$this->assertSame('Ali', $service->signerName($unnamed, $this->accounts['ali']));
		$this->assertSame('Wanjiru', $service->signerName(self::signRequest(103), $this->user('wanjiru-account')), 'named by the moderator');

		$renamed = [];
		$this->signRequests->method('update')->willReturnCallback(static function (SignRequest $signRequest) use (&$renamed): SignRequest {
			$renamed[] = [$signRequest->getId(), $signRequest->getDisplayName()];
			return $signRequest;
		});
		$unnamed->setSigned(new \DateTime(self::NOW));
		$service->signed(self::file(), $unnamed);
		$service->signed(self::file(), self::signRequest(103, new \DateTime(self::NOW)));

		$this->assertSame([[102, 'Ali']], $renamed, 'only the unnamed slot, only once signed');
	}

	public function testAFailureAfterASignatureNeverReachesTheSigner(): void {
		$this->drafts->method('findByFileId')->willThrowException(new \RuntimeException('database down'));

		$this->service()->signed(self::file(), self::signRequest(101));
		$this->addToAssertionCount(1);
	}

	public function testAnExpiredDraftIsDeletedWithItsFolderButASealedOneNever(): void {
		$this->files->method('getById')->willReturn(self::file());
		$folder = $this->createMock(Folder::class);
		$folder->method('getName')->willReturn('tuandikiane-' . self::DRAFT);
		$folder->expects($this->once())->method('delete');
		$source = $this->createMock(File::class);
		$source->method('getParent')->willReturn($folder);
		$this->folders->method('getFileByNodeId')->willReturnMap([[900, $source]]);
		$this->requestSignature->expects($this->once())->method('deleteRequestSignature')->with(['file' => ['fileId' => 42]]);
		$this->drafts->expects($this->once())->method('delete');

		$this->service()->deleteDraft(self::draft(expires: '2026-11-06T09:00:00+00:00'));
		$this->service()->deleteDraft(self::draft(TuandikianeDraft::STATUS_SENT));
	}
}
