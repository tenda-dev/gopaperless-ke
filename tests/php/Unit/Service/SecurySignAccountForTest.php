<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Db\UserElement;
use OCA\Libresign\Db\UserElementMapper;
use OCA\Libresign\Service\AccountService;
use OCA\Libresign\Service\SecurySignService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IServerContainer;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\User\Events\UserCreatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * accountFor: a MIMI account becomes a GoPaperless one that can sign from a
 * WhatsApp link, carrying MIMI's certificate copy and drawn signature.
 */
final class SecurySignAccountForTest extends TestCase {
	private const SUB = '3f9a0000-0000-0000-0000-00000000000a';
	private const OLDER = "-----BEGIN CERTIFICATE-----\nMIIBFTCBvaADAgECAgEBMAoGCCqGSM49BAMCMBUxEzARBgNVBAMMClRlc3Qgb2xk\nZXIwHhcNMjUwMTAxMDAwMDAwWhcNMjYwMTAxMDAwMDAwWjAVMRMwEQYDVQQDDApU\nZXN0IG9sZGVyMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEpPrsCKXBNpLwV7LF\nKJ0e+52CbZeWOeZq1IdmB4S4tIb35gwe9XiRM4iQUhLJjI53JiA4vcv3J6sV0aV4\nqp8ydzAKBggqhkjOPQQDAgNHADBEAiA+I5T/Ghk9ouBCcZCZeCVTOZ6lDoh0qJQK\nhY4o69QNhwIgc+or41urWDWhXXRJa16tHItZSisp0l5yltWpnkK6aPo=\n-----END CERTIFICATE-----\n";
	private const NEWER = "-----BEGIN CERTIFICATE-----\nMIIBFjCBvaADAgECAgEBMAoGCCqGSM49BAMCMBUxEzARBgNVBAMMClRlc3QgbmV3\nZXIwHhcNMjYwNjAxMDAwMDAwWhcNMjcwNjAxMDAwMDAwWjAVMRMwEQYDVQQDDApU\nZXN0IG5ld2VyMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEpPrsCKXBNpLwV7LF\nKJ0e+52CbZeWOeZq1IdmB4S4tIb35gwe9XiRM4iQUhLJjI53JiA4vcv3J6sV0aV4\nqp8ydzAKBggqhkjOPQQDAgNIADBFAiEAwamEVDwqXnHzlFFn/l6j4+7XmIByUKr1\n30Z5/s854owCIG62CMt7z1Mf73wDKkCGUJ3ygrv9DJhBDU0a02Da24yL\n-----END CERTIFICATE-----\n";

	/** @var array<string, array<string, string>> uid => key => value */
	private array $values = [];
	/** @var array<string, IUser> */
	private array $accounts = [];
	/** @var list<UserElement> */
	private array $elements = [];
	private array $saved = [];
	private array $created = [];
	private array $events = [];
	private int $status = 200;
	private array $person = [];

	private function person(array $overrides = []): array {
		return $overrides + [
			'subject' => self::SUB,
			'name' => 'Wanjiru Kamau',
			'email' => 'wanjiru@example.test',
			'signature' => 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\nscribble"),
			'certificate' => ['certificateId' => 7, 'pem' => self::NEWER, 'serialNumberHex' => '01', 'validUntil' => '2027-06-01'],
		];
	}

	private function account(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('setSystemEMailAddress')->willReturnCallback(function (string $email) use ($uid) {
			$this->values[$uid]['email'] = $email;
		});
		return $user;
	}

	private function service(): SecurySignService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = '') => match ($key) {
			'mimi_url' => 'https://gopaperless.mimi.ke',
			'mimi_client_secret' => 'secret',
			default => $default,
		});
		$config->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0) => $key === 'securysign_provider_id' ? 2 : $default);

		$systemConfig = $this->createMock(IConfig::class);
		$systemConfig->method('getUserValue')->willReturnCallback(fn (string $uid, string $app, string $key) => $this->values[$uid][$key] ?? '');
		$systemConfig->method('setUserValue')->willReturnCallback(function (string $uid, string $app, string $key, string $value) {
			$this->values[$uid][$key] = $value;
		});
		$systemConfig->method('getUsersForUserValue')->willReturnCallback(fn (string $app, string $key, string $value) => array_keys(array_filter($this->values, static fn ($v) => ($v[$key] ?? null) === $value)));

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(fn (string $uid) => $this->accounts[$uid] ?? null);

		$test = $this;
		$oidcUsers = new class($test) {
			public function __construct(private SecurySignAccountForTest $test) {
			}
			public function getOrCreate(int $providerId, string $uid): object {
				return $this->test->madeByUserOidc($providerId, $uid);
			}
			public function update(object $user): void {
			}
		};
		$mapper = $this->createMock(UserElementMapper::class);
		$mapper->method('findMany')->willReturnCallback(fn () => $this->elements);
		$accountService = $this->createMock(AccountService::class);
		$accountService->method('saveVisibleElement')->willReturnCallback(function (array $data, string $session, IUser $user) {
			$this->saved[] = [$user->getUID(), $data['file']['base64']];
		});
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event) {
			$this->events[] = $event;
		});
		$container = $this->createMock(IServerContainer::class);
		$container->method('get')->willReturnCallback(static fn (string $id) => match ($id) {
			IUserManager::class => $userManager,
			'OCA\\UserOIDC\\Db\\UserMapper' => $oidcUsers,
			UserElementMapper::class => $mapper,
			AccountService::class => $accountService,
			IEventDispatcher::class => $dispatcher,
		});

		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(function (string $url, array $options) {
			self::assertSame('https://gopaperless.mimi.ke/api/partner/person', $url);
			self::assertSame(['subject' => self::SUB], $options['json']);
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($this->status);
			$response->method('getBody')->willReturn(json_encode($this->person));
			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new SecurySignService($config, $systemConfig, $this->createMock(ISession::class), $this->createMock(IUserSession::class),
			$container, $clients, $this->createMock(LoggerInterface::class));
	}

	/** What user_oidc's UserMapper::getOrCreate leaves: a backend row, and the account it backs. */
	public function madeByUserOidc(int $providerId, string $uid): object {
		self::assertSame(2, $providerId);
		$this->created[] = $uid;
		$this->accounts[$uid] = $this->account($uid);
		return new class($uid) {
			public function __construct(private string $uid) {
			}
			public function getUserId(): string {
				return $this->uid;
			}
			public function getDisplayName(): ?string {
				return null;
			}
			public function setDisplayName(string $name): void {
			}
		};
	}

	public function testSomeoneNewGetsAnAccountMadeAsAFirstLoginWould(): void {
		$this->person = $this->person();
		$user = $this->service()->accountFor(self::SUB);

		self::assertSame('wanjiru@example.test', $user->getUID());
		self::assertSame(['wanjiru@example.test'], $this->created);
		self::assertSame('wanjiru@example.test', $this->values['wanjiru@example.test']['email']);
		self::assertSame(self::SUB, $this->values['wanjiru@example.test']['securysign_sub']);
		self::assertSame(self::NEWER, $this->values['wanjiru@example.test'][SecurySignService::REMEMBERED_CERTIFICATE]);
		self::assertSame([['wanjiru@example.test', 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\nscribble")]], $this->saved);
		self::assertCount(1, $this->events);
		self::assertInstanceOf(UserCreatedEvent::class, $this->events[0]);
	}

	public function testAnAccountALoginLeftIsFoundBySubAndNothingIsMade(): void {
		$this->accounts['old-uid'] = $this->account('old-uid');
		$this->values['old-uid'] = ['securysign_sub' => self::SUB];
		$this->person = $this->person(['email' => 'changed@example.test']);

		self::assertSame('old-uid', $this->service()->accountFor(self::SUB)->getUID());
		self::assertSame([], $this->created);
		self::assertSame([], $this->events);
	}

	public function testAnAccountWithTheAddressIsUsedAsALoginWouldUseIt(): void {
		$this->accounts['wanjiru@example.test'] = $this->account('wanjiru@example.test');
		$this->person = $this->person();

		self::assertSame('wanjiru@example.test', $this->service()->accountFor(self::SUB)->getUID());
		self::assertSame([], $this->created);
		self::assertSame(self::SUB, $this->values['wanjiru@example.test']['securysign_sub']);
	}

	public function testANewerCertificateFromALoginAndADrawnSignatureStay(): void {
		$this->accounts['wanjiru@example.test'] = $this->account('wanjiru@example.test');
		$this->values['wanjiru@example.test'] = ['securysign_sub' => self::SUB, SecurySignService::REMEMBERED_CERTIFICATE => self::NEWER];
		$this->elements = [new UserElement()];
		$this->person = $this->person(['certificate' => ['certificateId' => 6, 'pem' => self::OLDER]]);

		$this->service()->accountFor(self::SUB);
		self::assertSame(self::NEWER, $this->values['wanjiru@example.test'][SecurySignService::REMEMBERED_CERTIFICATE]);
		self::assertSame([], $this->saved);

		$this->values['wanjiru@example.test'][SecurySignService::REMEMBERED_CERTIFICATE] = self::OLDER;
		$this->person = $this->person();
		$this->service()->accountFor(self::SUB);
		self::assertSame(self::NEWER, $this->values['wanjiru@example.test'][SecurySignService::REMEMBERED_CERTIFICATE]);
	}

	public function testWithoutACertificateNoSignatureIsImported(): void {
		$this->person = $this->person(['certificate' => null]);
		self::assertNotNull($this->service()->accountFor(self::SUB));
		self::assertSame([], $this->saved);
		self::assertArrayNotHasKey(SecurySignService::REMEMBERED_CERTIFICATE, $this->values['wanjiru@example.test']);
	}

	public function testAnUnknownAccountOrOneWithoutAnAddressIsNull(): void {
		$this->status = 404;
		$this->person = ['error' => 'unknown_subject'];
		self::assertNull($this->service()->accountFor(self::SUB));

		$this->status = 200;
		$this->person = $this->person(['email' => null]);
		self::assertNull($this->service()->accountFor(self::SUB));
		self::assertSame([], $this->created);
	}

	public function testMimiFailingOrAnsweringForSomeoneElseThrows(): void {
		foreach ([[503, $this->person()], [403, ['error' => 'not_allowed']], [200, $this->person(['subject' => 'someone-else'])]] as [$status, $person]) {
			$this->status = $status;
			$this->person = $person;
			try {
				$this->service()->accountFor(self::SUB);
				self::fail('accepted HTTP ' . $status);
			} catch (\RuntimeException $e) {
				self::assertSame(503, $e->getCode());
			}
		}
		self::assertSame([], $this->created);
	}
}
