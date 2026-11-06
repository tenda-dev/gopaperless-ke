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
use OCA\Libresign\Service\RequestSignatureService;
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

final class TuandikianeServiceTest extends TestCase {
	private const DRAFT = '7d1f2c3a-4b5e-4f60-8a9b-0c1d2e3f4a5b';
	private const NOW = '2026-11-06T10:00:00+00:00';

	private array $settings = [
		'tuandikiane_api_key' => 'key-123',
		'tuandikiane_service_uid' => 'tuandikiane',
	];
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
	private TuandikianeClient&MockObject $client;
	private IURLGenerator&MockObject $urls;
	/** @var list<array{string, ?array}> */
	private array $stored = [];

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->users = $this->createMock(IUserManager::class);
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
		$this->client = $this->createMock(TuandikianeClient::class);
		$this->urls = $this->createMock(IURLGenerator::class);
		$this->urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params): string => 'https://gp.test/' . $route . '/' . $params['code'],
		);
		// Every draft write, as [status, slots].
		$this->drafts->method('update')->willReturnCallback(function (TuandikianeDraft $draft): TuandikianeDraft {
			$this->stored[] = [$draft->getStatus(), $draft->getSlots()];
			return $draft;
		});
		$this->identifyMethods->method('update')->willReturnArgument(0);
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
			$this->client, $this->urls, $this->createMock(LoggerInterface::class));
	}

	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn(ucfirst($uid));
		return $user;
	}

	private static function input(array $override = []): array {
		return $override + [
			'reference' => 'DOC-9EB7DE75CCD8',
			'name' => 'Loan agreement between Neo and Ali',
			'file' => ['base64' => 'JVBERi0xLjQK'],
			'signers' => [
				['key' => 's1', 'display_name' => 'Neo Kamau', 'phone' => '+254711000001', 'email' => null, 'is_sender' => true],
				['key' => 's2', 'display_name' => 'Ali Hassan', 'phone' => '+254722000002', 'email' => 'Ali@Example.com', 'is_sender' => false],
			],
			'boxes' => [
				['signer' => 's1', 'page' => 1, 'left' => 72, 'top' => 640, 'width' => 180, 'height' => 48],
				['signer' => 's2', 'page' => 2, 'left' => 320, 'top' => 640, 'width' => 180, 'height' => 48],
			],
		];
	}

	private static function file(string $owner = 'tuandikiane'): FileEntity {
		$file = new FileEntity();
		$file->setId(42);
		$file->setUuid('f1e2d3c4-0000-4000-8000-000000000042');
		$file->setUserId($owner);
		$file->setNodeId(900);
		$file->setName('Loan agreement between Neo and Ali');
		$file->setMetadata(['d' => [['w' => 595, 'h' => 842], ['w' => 595, 'h' => 842]]]);
		return $file;
	}

	private static function signRequest(int $id, int $order, ?\DateTime $signed = null, SignRequestStatus $status = SignRequestStatus::ABLE_TO_SIGN): SignRequest {
		$signRequest = new SignRequest();
		$signRequest->setId($id);
		$signRequest->setUuid(sprintf('00000000-0000-4000-8000-%012d', $id));
		$signRequest->setFileId(42);
		$signRequest->setDisplayName($order === 1 ? 'Neo Kamau' : 'Ali Hassan');
		$signRequest->setSigningOrder($order);
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

	private static function draft(string $status = TuandikianeDraft::STATUS_DRAFT, string $expires = '2026-11-07T10:00:00+00:00', ?string $s1 = null, ?string $s2 = null): TuandikianeDraft {
		$draft = new TuandikianeDraft();
		$draft->setId(5);
		$draft->setDraftUuid(self::DRAFT);
		$draft->setReference('DOC-9EB7DE75CCD8');
		$draft->setFileId(42);
		$draft->setStatus($status);
		$draft->setExpiresAt(new \DateTime($expires));
		$draft->setSlots([
			['key' => 's1', 'phone' => '+254711000001', 'sender' => true, 'signRequestId' => 101, 'uid' => $s1, 'card' => false],
			['key' => 's2', 'phone' => '+254722000002', 'sender' => false, 'signRequestId' => 102, 'uid' => $s2, 'card' => false],
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
	private function creating(?array &$saved, array &$elements): IUser {
		$service = $this->user('tuandikiane');
		$this->users->method('get')->willReturnMap([['tuandikiane', $service]]);
		$this->drafts->method('findByReference')->willReturn(null);
		$this->drafts->method('insert')->willReturnArgument(0);
		$this->requestSignature->method('save')->willReturnCallback(static function (array $data) use (&$saved): FileEntity {
			$saved = $data;
			return self::file();
		});
		$this->signRequests->method('getByIdentifierAndFileId')->willReturnCallback(
			static fn (string $key, string $value): SignRequest => self::signRequest(str_contains($value, '-s1@') ? 101 : 102, str_contains($value, '-s1@') ? 1 : 2),
		);
		$this->fileElementService->method('saveVisibleElement')->willReturnCallback(static function (array $element) use (&$elements) {
			$elements[] = $element;
			return new \OCA\Libresign\Db\FileElement();
		});
		return $service;
	}

	public function testASenderWhoDoesNotSignAndUnnamedSignersAreAccepted(): void {
		$saved = null;
		$elements = [];
		$this->creating($saved, $elements);
		$input = self::input();
		$input['signers'][0] = ['key' => 's1', 'display_name' => '', 'phone' => '+254711000001', 'email' => null, 'is_sender' => false];
		$input['signers'][1]['email'] = null;

		$this->service()->createDraft($input);

		$this->assertSame(['Signer 1', 'Ali Hassan'], array_column($saved['signers'], 'displayName'));
		[, $slots] = end($this->stored);
		$this->assertSame([false, false], array_column($slots, 'sender'));
		$this->assertSame([false, true], array_column($slots, 'named'));
	}

	public function testADraftGetsASlotPerSignerAndEachBoxOnItsSignersSlot(): void {
		$service = $this->user('tuandikiane');
		$this->users->method('get')->willReturnMap([['tuandikiane', $service]]);
		$this->drafts->method('findByReference')->willReturn(null);
		$this->drafts->expects($this->once())->method('insert')->willReturnCallback(static function (TuandikianeDraft $draft): TuandikianeDraft {
			$draft->setId(5);
			return $draft;
		});
		$saved = null;
		$this->requestSignature->expects($this->once())->method('save')->willReturnCallback(static function (array $data) use (&$saved): FileEntity {
			$saved = $data;
			return self::file();
		});
		$this->signRequests->method('getByIdentifierAndFileId')->willReturnCallback(
			static fn (string $key, string $value, int $fileId): ?SignRequest => match (true) {
				$key === 'email' && $fileId === 42 && str_ends_with($value, '-s1@placeholder.securesign.local') => self::signRequest(101, 1),
				$key === 'email' && $fileId === 42 && $value === 'ali@example.com' => self::signRequest(102, 2),
				default => null,
			},
		);
		$elements = [];
		$this->fileElementService->method('saveVisibleElement')->willReturnCallback(static function (array $element) use (&$elements) {
			$elements[] = $element;
			return new \OCA\Libresign\Db\FileElement();
		});

		$result = $this->service()->createDraft(self::input());

		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $result['draft_uuid']);
		$this->assertMatchesRegularExpression('#^https://gp\.test/libresign\.tuandikiane\.review/' . $result['draft_uuid'] . '\.[A-Za-z0-9_-]{22}$#', $result['review_url']);
		$this->assertSame('2026-11-07T10:00:00+00:00', $result['expires_at']);

		$this->assertSame(FileStatus::DRAFT->value, $saved['status']);
		$this->assertSame($service, $saved['userManager']);
		$this->assertSame('tuandikiane-' . $result['draft_uuid'], $saved['settings']['folderName']);
		$this->assertSame('tuandikiane-' . $result['draft_uuid'] . '-s1@placeholder.securesign.local', $saved['signers'][0]['identifyMethods'][0]['value']);
		$this->assertSame([['method' => 'email', 'value' => 'ali@example.com']], $saved['signers'][1]['identifyMethods']);
		$this->assertSame([0, 0], array_column($saved['signers'], 'notify'));
		$this->assertSame([1, 2], array_column($saved['signers'], 'signingOrder'));

		$this->assertSame([101, 102], array_column($elements, 'signRequestId'));
		$this->assertSame(['page' => 2, 'left' => 320, 'top' => 640, 'width' => 180, 'height' => 48], $elements[1]['coordinates']);
		$this->assertSame([42, 42], array_column($elements, 'fileId'));
	}

	public function testTheSameReferenceGetsTheSameDraftBack(): void {
		$this->drafts->method('findByReference')->willReturn(self::draft());
		$this->drafts->expects($this->never())->method('insert');
		$this->requestSignature->expects($this->never())->method('save');

		$result = $this->service()->createDraft(self::input());

		$this->assertSame(self::DRAFT, $result['draft_uuid']);
		$this->assertSame('https://gp.test/libresign.tuandikiane.review/' . $this->service()->reviewCode(self::DRAFT), $result['review_url']);
	}

	public function testTheSameReferenceAfterItsDraftExpiredUnsentMakesANewOne(): void {
		$this->drafts->method('findByReference')->willReturn(self::draft(expires: '2026-11-06T09:00:00+00:00'));
		$this->files->method('getById')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('gone'));
		$this->drafts->expects($this->once())->method('delete');
		$this->drafts->expects($this->once())->method('insert')->willThrowException(new \RuntimeException('stop here'));
		$this->users->method('get')->willReturn($this->user('tuandikiane'));

		$this->expectExceptionMessage('stop here');
		$this->service()->createDraft(self::input());
	}

	public static function badBodies(): array {
		$two = self::input()['signers'];
		$two[1]['is_sender'] = true;
		return [
			'no reference' => [['reference' => ' ']],
			'two senders' => [['signers' => $two]],
			'an empty email' => [['signers' => [['key' => 's1', 'display_name' => 'Neo', 'phone' => '+254711000001', 'email' => '', 'is_sender' => true]]]],
			'no display name at all' => [['signers' => [['key' => 's1', 'phone' => '+254711000001', 'email' => null, 'is_sender' => true]]]],
			'a local number' => [['signers' => [['key' => 's1', 'display_name' => 'Neo', 'phone' => '0711000001', 'email' => null, 'is_sender' => true]]]],
			'a box for no one' => [['boxes' => [['signer' => 's9', 'page' => 1, 'left' => 0, 'top' => 0, 'width' => 10, 'height' => 10]]]],
			'a box off the page' => [['boxes' => [['signer' => 's1', 'page' => 0, 'left' => 0, 'top' => 0, 'width' => 10, 'height' => 10]]]],
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

	public function testTheReviewCodeNamesItsDraftOnlyWhileItCanBeSent(): void {
		$service = $this->service();
		$code = $service->reviewCode(self::DRAFT);
		$drafts = [self::draft(), self::draft(expires: '2026-11-06T09:59:59+00:00'), self::draft(TuandikianeDraft::STATUS_SENT)];
		$this->drafts->method('findByDraftUuid')->willReturnOnConsecutiveCalls(...$drafts);

		$this->assertSame(self::DRAFT, $service->draftForCode($code)?->getDraftUuid());
		$this->assertNull($service->draftForCode($code), 'expired');
		$this->assertNull($service->draftForCode($code), 'sent');
	}

	public function testAForgedReviewCodeIsNotLookedUp(): void {
		$this->drafts->expects($this->never())->method('findByDraftUuid');
		$service = $this->service();
		$code = $service->reviewCode(self::DRAFT);

		$this->assertNull($service->draftForCode(self::DRAFT . '.' . str_repeat('A', 22)));
		$this->assertNull($service->draftForCode(self::DRAFT));
		$this->assertNull($service->draftForCode('8d1f2c3a-4b5e-4f60-8a9b-0c1d2e3f4a5b' . substr($code, 36)));
	}

	/**
	 * The folder calls of a send: the draft's file in the service account's
	 * folder, and an empty folder for it in the sender's.
	 *
	 * @return array{copy: File&MockObject, holderFolder: Folder&MockObject}
	 */
	private function folders(): array {
		$holderFolder = $this->createMock(Folder::class);
		$holderFolder->method('getName')->willReturn('tuandikiane-' . self::DRAFT);
		$source = $this->createMock(File::class);
		$source->method('getName')->willReturn('Loan agreement between Neo and Ali.pdf');
		$source->method('getContent')->willReturn('%PDF-1.4');
		$source->method('getParent')->willReturn($holderFolder);
		$this->folders->method('getFileByNodeId')->willReturnMap([[900, $source]]);
		$copy = $this->createMock(File::class);
		$copy->method('getId')->willReturn(950);
		$draftFolder = $this->createMock(Folder::class);
		$draftFolder->method('nodeExists')->willReturn(false);
		$draftFolder->method('newFile')->with('Loan agreement between Neo and Ali.pdf', '%PDF-1.4')->willReturn($copy);
		$base = $this->createMock(Folder::class);
		$base->method('nodeExists')->willReturn(false);
		$base->method('newFolder')->with('tuandikiane-' . self::DRAFT)->willReturn($draftFolder);
		$this->folders->method('getFolder')->willReturn($base);
		return ['copy' => $copy, 'holderFolder' => $holderFolder];
	}

	public function testSendMovesTheFileBindsTheSenderPublishesAndSendsTheOtherCards(): void {
		$neo = $this->user('neo');
		$file = self::file();
		$this->files->method('getById')->willReturn($file);
		$folders = $this->folders();
		$folders['holderFolder']->expects($this->once())->method('delete');
		$this->drafts->method('findByDraftUuidForUpdate')->willReturn(self::draft());
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');
		$this->files->expects($this->once())->method('update')->willReturnCallback(function (FileEntity $updated): FileEntity {
			$this->assertSame('neo', $updated->getUserId());
			$this->assertSame(950, $updated->getNodeId());
			return $updated;
		});
		$methods = [101 => [self::method(101, 'email', 'tuandikiane-' . self::DRAFT . '-s1@placeholder.securesign.local')], 102 => [self::method(102, 'email', 'ali@example.com')]];
		$this->identifyMethods->method('getIdentifyMethodsFromSignRequestId')->willReturnCallback(static fn (int $id): array => $methods[$id]);
		$this->identifyMethodService->expects($this->once())->method('clearCache');
		$this->signRequests->method('getByFileId')->willReturn([self::signRequest(101, 1), self::signRequest(102, 2)]);
		$this->signRequests->method('getById')->willReturnCallback(static fn (int $id): SignRequest => self::signRequest($id, $id - 100));
		$published = null;
		$this->requestSignature->expects($this->once())->method('save')->willReturnCallback(static function (array $data) use (&$published, $file): FileEntity {
			$published = $data;
			return $file;
		});
		$this->users->method('get')->willReturnMap([['neo', $neo]]);
		$this->whatsApp->method('subFor')->with($neo)->willReturn('sub-neo');
		$cards = [];
		$this->whatsApp->expects($this->exactly(2))->method('sendTo')
			->willReturnCallback(static function (FileEntity $sent, SignRequest $signRequest, IUser $sender, string $sub, string $to) use (&$cards, $file, $neo): array {
				self::assertSame([$file, $neo], [$sent, $sender]);
				$cards[] = [$signRequest->getId(), $sub, $to];
				return ['status' => 'sent', 'error' => null, 'availableAgainAt' => null];
			});
		$this->whatsApp->method('linkCode')->willReturnCallback(static fn (SignRequest $signRequest): string => $signRequest->getUuid() . '.mac');
		$this->client->expects($this->once())->method('event')->with(self::DRAFT, 'sent');

		$url = $this->service()->send(self::draft(), $neo);

		$this->assertSame('https://gp.test/libresign.whatsAppSign.page/00000000-0000-4000-8000-000000000101.mac', $url);
		$this->assertSame('account', $methods[101][0]->getIdentifierKey());
		$this->assertSame('neo', $methods[101][0]->getIdentifierValue());
		$this->assertSame('email', $methods[102][0]->getIdentifierKey(), 'only the sender is bound on Send');

		$this->assertSame(FileStatus::ABLE_TO_SIGN->value, $published['status']);
		$this->assertSame($file->getUuid(), $published['uuid']);
		$this->assertSame($neo, $published['userManager']);
		$this->assertSame([
			['identifyMethods' => [['method' => 'account', 'value' => 'neo']], 'displayName' => 'Neo Kamau', 'signingOrder' => 1, 'notify' => 0],
			['identifyMethods' => [['method' => 'email', 'value' => 'ali@example.com']], 'displayName' => 'Ali Hassan', 'signingOrder' => 2, 'notify' => 0],
		], $published['signers']);

		[$status, $slots] = $this->stored[0];
		$this->assertSame(TuandikianeDraft::STATUS_SENT, $status);
		$this->assertSame('neo', $slots[0]['uid']);
		$this->assertNull($slots[1]['uid']);
		// The sender gets their card too, under their own sub; the others under their slot's name.
		$this->assertSame([[101, 'sub-neo', '+254711000001'], [102, 'tuandikiane:' . self::DRAFT . ':s2', '+254722000002']], $cards);
		$this->assertSame([true, true], array_column(end($this->stored)[1], 'card'), 'the cards are remembered as sent');
	}

	public function testSendAfterSomeoneElseSentItLeavesNothingBehind(): void {
		$this->files->method('getById')->willReturn(self::file());
		$folders = $this->folders();
		$folders['copy']->expects($this->once())->method('delete');
		$this->drafts->method('findByDraftUuidForUpdate')->willReturn(self::draft(TuandikianeDraft::STATUS_SENT, s1: 'zawadi'));
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->files->expects($this->never())->method('update');
		$this->requestSignature->expects($this->never())->method('save');
		$this->client->expects($this->never())->method('event');

		try {
			$this->service()->send(self::draft(), $this->user('neo'));
			$this->fail('Sent twice');
		} catch (LibresignException $e) {
			$this->assertSame(410, $e->getCode());
		}
	}

	private function sentDraftForCard(?string $s2 = null): void {
		$this->whatsApp->method('signRequestForCode')->willReturnMap([['card-code', self::signRequest(102, 2)]]);
		$this->drafts->method('findByFileId')->willReturn(self::draft(TuandikianeDraft::STATUS_SENT, s1: 'neo', s2: $s2));
		$this->drafts->method('findByDraftUuidForUpdate')->willReturn(self::draft(TuandikianeDraft::STATUS_SENT, s1: 'neo', s2: $s2));
	}

	public function testTheCardOfAnUntakenSlotTakesItForTheSignerWhoProvedItIsThem(): void {
		$this->sentDraftForCard();
		$method = self::method(102, 'email', 'ali@example.com');
		$this->identifyMethods->method('getIdentifyMethodsFromSignRequestId')->with(102)->willReturn([$method]);
		$this->db->expects($this->once())->method('commit');

		$this->assertNotNull($this->service()->unboundSlot('card-code'));
		$this->service()->bindCard('card-code', $this->user('ali'));

		$this->assertSame(['account', 'ali'], [$method->getIdentifierKey(), $method->getIdentifierValue()]);
		$this->assertSame('ali', end($this->stored)[1][1]['uid']);
	}

	public function testAnUnnamedSlotTakesTheNameOfTheAccountThatTookIt(): void {
		$this->whatsApp->method('signRequestForCode')->willReturn(self::signRequest(102, 2));
		$draft = self::draft(TuandikianeDraft::STATUS_SENT, s1: 'neo');
		$slots = $draft->getSlots();
		$slots[1]['named'] = false;
		$draft->setSlots($slots);
		$this->drafts->method('findByFileId')->willReturn($draft);
		$this->drafts->method('findByDraftUuidForUpdate')->willReturn($draft);
		$this->identifyMethods->method('getIdentifyMethodsFromSignRequestId')->willReturn([self::method(102, 'email', 'tuandikiane-x-s2@placeholder.securesign.local')]);
		$slot = self::signRequest(102, 2);
		$slot->setDisplayName('Signer 2');
		$this->signRequests->method('getById')->with(102)->willReturn($slot);
		$this->signRequests->expects($this->once())->method('update')->willReturnArgument(0);

		$this->service()->bindCard('card-code', $this->user('wanjiru'));

		$this->assertSame('Wanjiru', $slot->getDisplayName());
	}

	public function testSendWhenTheSenderDoesNotSignBindsNoOneAndCardsEveryone(): void {
		$file = self::file();
		$this->files->method('getById')->willReturn($file);
		$this->folders();
		$draft = self::draft();
		$slots = $draft->getSlots();
		$slots[0]['sender'] = false;
		$draft->setSlots($slots);
		$this->drafts->method('findByDraftUuidForUpdate')->willReturn($draft);
		$this->files->method('update')->willReturnArgument(0);
		$this->identifyMethods->method('getIdentifyMethodsFromSignRequestId')->willReturnCallback(
			static fn (int $id): array => [self::method($id, 'email', "slot-$id@example.com")],
		);
		$this->identifyMethods->expects($this->never())->method('update');
		$this->signRequests->method('getByFileId')->willReturn([self::signRequest(101, 1), self::signRequest(102, 2)]);
		$this->signRequests->method('getById')->willReturnCallback(static fn (int $id): SignRequest => self::signRequest($id, $id - 100));
		$this->requestSignature->method('save')->willReturn($file);
		$cards = [];
		$this->whatsApp->method('sendTo')->willReturnCallback(static function ($file, SignRequest $signRequest, IUser $sender, string $sub, string $to) use (&$cards): array {
			$cards[] = $to;
			return ['status' => 'sent', 'error' => null, 'availableAgainAt' => null];
		});
		$this->client->expects($this->once())->method('event')->with(self::DRAFT, 'sent');

		$this->assertNull($this->service()->send(self::draft(), $this->user('brian')));
		$this->assertSame(['+254711000001', '+254722000002'], $cards);
		$this->assertSame([null, null], array_column(end($this->stored)[1], 'uid'));
	}

	public function testOneAccountCannotTakeTwoSlotsOfAnAgreement(): void {
		$this->sentDraftForCard();
		$this->identifyMethods->expects($this->never())->method('update');
		$this->db->expects($this->once())->method('rollBack');

		try {
			$this->service()->bindCard('card-code', $this->user('neo'));
			$this->fail('Bound the sender to a second slot');
		} catch (LibresignException $e) {
			$this->assertSame(409, $e->getCode());
		}
	}

	public function testATakenSlotSendsItsCardToTheNormalCard(): void {
		$this->sentDraftForCard('ali');

		$this->assertNull($this->service()->unboundSlot('card-code'));
	}

	public function testEachSignatureIsReportedAndTheLastCompletesTheDraft(): void {
		$this->drafts->method('findByFileId')->with(42)->willReturn(self::draft(TuandikianeDraft::STATUS_SENT, s1: 'neo', s2: 'ali'));
		$signed = new \DateTime(self::NOW);
		$this->signRequests->method('getByFileId')->willReturn([self::signRequest(101, 1, $signed), self::signRequest(102, 2, $signed)]);
		$events = [];
		$this->client->method('event')->willReturnCallback(static function (string $draft, string $event, ?string $signer = null) use (&$events): void {
			$events[] = [$draft, $event, $signer];
		});

		$this->service()->signed(self::file('neo'), self::signRequest(102, 2, $signed));

		$this->assertSame([[self::DRAFT, 'signed', 's2'], [self::DRAFT, 'completed', null]], $events);
	}

	public function testASignatureBeforeTheLastSendsTheNextSignersCard(): void {
		$this->drafts->method('findByFileId')->willReturn(self::draft(TuandikianeDraft::STATUS_SENT, s1: 'neo'));
		$this->signRequests->method('getByFileId')->willReturn([self::signRequest(101, 1, new \DateTime(self::NOW)), self::signRequest(102, 2)]);
		$this->signRequests->method('getById')->willReturnCallback(
			static fn (int $id): SignRequest => $id === 101 ? self::signRequest(101, 1, new \DateTime(self::NOW)) : self::signRequest(102, 2),
		);
		$neo = $this->user('neo');
		$this->users->method('get')->willReturnMap([['neo', $neo]]);
		$this->client->expects($this->once())->method('event')->with(self::DRAFT, 'signed', 's1');
		$this->whatsApp->expects($this->once())->method('sendTo')->willReturn(['status' => 'sent', 'error' => null, 'availableAgainAt' => null]);

		$this->service()->signed(self::file('neo'), self::signRequest(101, 1, new \DateTime(self::NOW)));
	}

	public function testASignatureOnAnyOtherDocumentIsLeftAlone(): void {
		$this->drafts->method('findByFileId')->willReturn(null);
		$this->client->expects($this->never())->method('event');

		$this->service()->signed(self::file('neo'), self::signRequest(101, 1));
	}

	public function testAFailureAfterASignatureNeverReachesTheSigner(): void {
		$this->drafts->method('findByFileId')->willThrowException(new \RuntimeException('database down'));

		$this->service()->signed(self::file('neo'), self::signRequest(101, 1));
		$this->addToAssertionCount(1);
	}

	public function testAnExpiredDraftIsDeletedWithItsFileButASentOneNever(): void {
		$this->files->method('getById')->willReturn(self::file());
		$folders = $this->folders();
		$folders['holderFolder']->expects($this->once())->method('delete');
		$this->requestSignature->expects($this->once())->method('deleteRequestSignature')->with(['file' => ['fileId' => 42]]);
		$this->drafts->expects($this->once())->method('delete');

		$this->service()->deleteDraft(self::draft(expires: '2026-11-06T09:00:00+00:00'));
		$this->service()->deleteDraft(self::draft(TuandikianeDraft::STATUS_SENT, s1: 'neo'));
	}
}
