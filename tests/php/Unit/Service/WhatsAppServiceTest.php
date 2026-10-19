<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Db\File as FileEntity;
use OCA\Libresign\Db\IdentifyMethod;
use OCA\Libresign\Db\IdentifyMethodMapper;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Enum\SignRequestStatus;
use OCA\Libresign\Service\SecurySignService;
use OCA\Libresign\Service\WhatsAppService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class WhatsAppServiceTest extends TestCase {
	private const UUID = '0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b10';

	private IClient&MockObject $client;
	private IUser&MockObject $signer;
	private bool $enabled = true;
	/** @var list<array{string, string, array}> */
	private array $calls = [];
	private SignRequestMapper&MockObject $signRequests;

	protected function setUp(): void {
		$this->client = $this->createMock(IClient::class);
		$this->signer = $this->createMock(IUser::class);
		$this->signer->method('getUID')->willReturn('ann@example.com');
		$this->signer->method('getDisplayName')->willReturn('Ann');
		$this->signRequests = $this->createMock(SignRequestMapper::class);
	}

	private function service(string $backend = 'user_oidc'): WhatsAppService {
		$this->signer->method('getBackendClassName')->willReturn($backend);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(fn () => $this->enabled);
		$appConfig->method('getValueString')->willReturnMap([
			['libresign', 'whatsapp_api_url', '', false, 'http://payments.test/'],
			['libresign', 'whatsapp_api_key', '', false, 'key-123'],
		]);
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('sub-ann');
		$config->method('getSystemValueString')->willReturnMap([['secret', '', 'instance-secret']]);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->signer);
		$account = new IdentifyMethod();
		$account->setIdentifierKey('account');
		$account->setIdentifierValue('ann@example.com');
		$methods = $this->createMock(IdentifyMethodMapper::class);
		$methods->method('getIdentifyMethodsFromSignRequestId')->willReturn([$account]);
		$signa = $this->createMock(SecurySignService::class);
		$signa->method('providerId')->willReturn(2);
		$http = $this->createMock(IClientService::class);
		$http->method('newClient')->willReturn($this->client);
		return new WhatsAppService($appConfig, $config, $this->createMock(IDBConnection::class), $users, $methods, $this->signRequests, $signa,
			$this->createMock(IURLGenerator::class), $http, $this->createMock(LoggerInterface::class));
	}

	private function answer(int $status, array $body): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn(json_encode($body));
		$record = function (string $url, array $options) use ($response): IResponse {
			$this->calls[] = [$url, $options];
			return $response;
		};
		$this->client->method('get')->willReturnCallback($record);
		$this->client->method('post')->willReturnCallback($record);
	}

	private static function file(string $type = 'file'): FileEntity {
		$file = new FileEntity();
		$file->setName('NDA.pdf');
		$file->setNodeType($type);
		return $file;
	}

	private static function signRequest(SignRequestStatus $status = SignRequestStatus::ABLE_TO_SIGN, ?array $metadata = null): SignRequest {
		$signRequest = new SignRequest();
		$signRequest->setId(7);
		$signRequest->setUuid(self::UUID);
		$signRequest->setStatus($status->value);
		if ($metadata !== null) {
			$signRequest->setMetadata($metadata);
		}
		return $signRequest;
	}

	public function testTheRowGetsTheMaskedNumberAndNeverAsksForMore(): void {
		$this->answer(200, ['available' => true, 'masked' => '+254 7•• ••• 678', 'reason' => null, 'last_sent_at' => null, 'available_again_at' => null, 'last_error' => 'twilio_63016']);

		$row = $this->service()->recipient(self::file(), self::signRequest());

		self::assertSame(['available' => true, 'masked' => '+254 7•• ••• 678', 'reason' => null, 'lastSentAt' => null, 'availableAgainAt' => null, 'waitReason' => null, 'lastError' => 'twilio_63016', 'chosen' => false], $row);
		self::assertSame('http://payments.test/external/whatsapp/recipient?sub=sub-ann&sign_request_uuid=' . self::UUID, $this->calls[0][0]);
		self::assertSame('key-123', $this->calls[0][1]['headers']['X-API-Key']);
	}

	public function testOnlyAccountsFromTheSecurySignLoginQualify(): void {
		$this->answer(200, []);

		$row = $this->service('Database')->recipient(self::file(), self::signRequest());

		self::assertSame('password_account', $row['reason']);
		self::assertSame([], $this->calls);
	}

	public function testNothingIsAskedWhenTheFeatureIsOffOrTheDocumentIsAnEnvelope(): void {
		$this->answer(200, []);
		self::assertSame('envelope', $this->service()->recipient(self::file('envelope'), self::signRequest())['reason']);
		$this->enabled = false;
		self::assertSame('disabled', $this->service()->recipient(self::file(), self::signRequest())['reason']);
		self::assertSame([], $this->calls);
	}

	public function testASignerWhoseTurnHasNotComeIsNotMessaged(): void {
		$this->answer(200, ['status' => 'sent', 'error' => null]);

		$result = $this->service()->send(self::file(), self::signRequest(SignRequestStatus::DRAFT), $this->signer);

		self::assertSame(['status' => 'failed', 'error' => 'not_their_turn', 'availableAgainAt' => null], $result);
		self::assertSame([], $this->calls);
	}

	public function testSendingNamesTheSenderAndDocumentAndReportsTheOutcome(): void {
		$this->answer(200, ['status' => 'sent', 'error' => null]);

		$result = $this->service()->send(self::file(), self::signRequest(), $this->signer);

		self::assertSame(['status' => 'sent', 'error' => null, 'availableAgainAt' => null], $result);
		self::assertSame('http://payments.test/external/whatsapp/sign-request', $this->calls[0][0]);
		$json = $this->calls[0][1]['json'];
		self::assertStringStartsWith(self::UUID . '.', $json['link_code']);
		unset($json['link_code']);
		self::assertSame(['sub' => 'sub-ann', 'sign_request_uuid' => self::UUID, 'sender_name' => 'Ann', 'document_name' => 'NDA.pdf'], $json);
	}

	public function testTheLinkCodeLeadsBackToItsSignRequestAndNothingElse(): void {
		$signRequest = self::signRequest();
		$this->signRequests->method('getByUuid')->willReturnCallback(
			fn (string $uuid) => $uuid === self::UUID ? $signRequest : throw new \OCP\AppFramework\Db\DoesNotExistException('none'),
		);
		$service = $this->service();
		$code = $service->linkCode($signRequest);

		self::assertSame($signRequest, $service->signRequestForCode($code));
		// The uuid alone is in the signing email: it must not open the no-login page.
		self::assertNull($service->signRequestForCode(self::UUID));
		self::assertNull($service->signRequestForCode(self::UUID . '.' . str_repeat('A', 22)));
		[, $mac] = explode('.', $code, 2);
		self::assertNull($service->signRequestForCode('0b5c2b2e-8a4f-4c9e-9d55-2f1a7c3e9b11.' . $mac));
	}

	public function testAPaymentsServiceThatDoesNotAnswerIsAFailedSendNotAnError(): void {
		$this->client->method('post')->willThrowException(new \RuntimeException('cURL error 28: Operation timed out'));
		$this->client->method('get')->willThrowException(new \RuntimeException('cURL error 28: Operation timed out'));
		$service = $this->service();

		self::assertSame(['status' => 'failed', 'error' => 'unreachable', 'availableAgainAt' => null], $service->send(self::file(), self::signRequest(), $this->signer));
		self::assertSame('unavailable', $service->recipient(self::file(), self::signRequest())['reason']);
	}

	public function testOnlyKenyanMobileNumbersAreTaken(): void {
		self::assertSame('254712345678', WhatsAppService::normalize('0712 345 678'));
		self::assertSame('254712345678', WhatsAppService::normalize('+254 712-345-678'));
		self::assertSame('254110345678', WhatsAppService::normalize('0110345678'));
		self::assertNull(WhatsAppService::normalize('0201234567'));
		self::assertNull(WhatsAppService::normalize('+1 415 555 0100'));
		self::assertSame('+254 7•• ••• 678', WhatsAppService::mask('254712345678'));
	}

	public function testChoosingWhatsAppKeepsTheTypedNumberOnTheSignRequest(): void {
		$signRequest = self::signRequest(SignRequestStatus::DRAFT, ['notify' => [['method' => 'account']]]);
		$this->signRequests->expects(self::exactly(2))->method('update');
		$service = $this->service();

		self::assertSame(['send' => true, 'masked' => '+254 7•• ••• 432'], $service->choose($signRequest, true, '0798 765 432'));
		self::assertSame(['to' => '254798765432'], $service->choice($signRequest));
		self::assertSame([['method' => 'account']], $signRequest->getMetadata()['notify']);

		$service->choose($signRequest, false, '');
		self::assertNull($service->choice($signRequest));
	}

	public function testANumberThatIsNotKenyanMobileIsRefused(): void {
		$this->signRequests->expects(self::never())->method('update');
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->choose(self::signRequest(), true, '+1 415 555 0100');
	}

	public function testATypedNumberIsSentAndShownMasked(): void {
		$this->answer(200, ['status' => 'sent', 'error' => null, 'available' => false, 'reason' => 'no_number']);
		$signRequest = self::signRequest(metadata: ['whatsapp' => ['to' => '254798765432']]);
		$service = $this->service();

		$row = $service->recipient(self::file(), $signRequest);
		self::assertTrue($row['available']);
		self::assertSame('+254 7•• ••• 432', $row['masked']);
		self::assertTrue($row['chosen']);

		$service->send(self::file(), $signRequest, $this->signer);
		self::assertSame('254798765432', $this->calls[1][1]['json']['to']);
	}

	public function testRequestingSendsOnlyWhenWhatsAppWasChosen(): void {
		$this->answer(200, ['status' => 'sent', 'error' => null]);
		$file = self::file();
		$file->setUserId('admin');
		$service = $this->service();

		$service->requested($file, self::signRequest());
		self::assertSame([], $this->calls);

		$service->requested($file, self::signRequest(metadata: ['whatsapp' => ['to' => null]]));
		self::assertSame('http://payments.test/external/whatsapp/sign-request', $this->calls[0][0]);
		self::assertArrayNotHasKey('to', $this->calls[0][1]['json']);
	}

	public function testTheAddSignerFormLooksUpTheAccountBeforeThereIsASignRequest(): void {
		$this->answer(200, ['available' => true, 'masked' => '+254 7•• ••• 678', 'reason' => null]);

		self::assertSame(['available' => true, 'masked' => '+254 7•• ••• 678', 'reason' => null], $this->service()->account($this->signer));
		self::assertSame('http://payments.test/external/whatsapp/recipient?sub=sub-ann', $this->calls[0][0]);
		self::assertSame('not_account', $this->service()->account(null)['reason']);
	}

	public function testAHeldSignerComesBackWithTheTimeAndWhy(): void {
		$this->answer(429, ['detail' => ['code' => 'too_soon', 'reason' => 'retry_wait', 'available_again_at' => '2026-10-07T09:00:00+00:00']]);

		$result = $this->service()->send(self::file(), self::signRequest(), $this->signer);

		self::assertSame(['status' => 'too_soon', 'error' => null, 'availableAgainAt' => '2026-10-07T09:00:00+00:00', 'waitReason' => 'retry_wait'], $result);
	}
}
