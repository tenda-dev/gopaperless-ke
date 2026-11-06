<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Listener;

use OCA\Libresign\Db\File as FileEntity;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Events\SignedEvent;
use OCA\Libresign\Listener\TuandikianeSignedListener;
use OCA\Libresign\Service\IdentifyMethod\IIdentifyMethod;
use OCA\Libresign\Service\TuandikianeClient;
use OCA\Libresign\Service\TuandikianeService;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TuandikianeSignedListenerTest extends TestCase {
	public function testASignatureGoesToTheServiceWithItsFileAndSignRequest(): void {
		$file = new FileEntity();
		$signRequest = new SignRequest();
		$service = $this->createMock(TuandikianeService::class);
		$service->expects($this->once())->method('signed')->with($file, $signRequest);
		$event = new SignedEvent($signRequest, $file, $this->createMock(IIdentifyMethod::class), $this->createMock(IUser::class), $this->createMock(File::class));

		(new TuandikianeSignedListener($service))->handle($event);
	}

	public function testAnyOtherEventIsIgnored(): void {
		$service = $this->createMock(TuandikianeService::class);
		$service->expects($this->never())->method('signed');

		(new TuandikianeSignedListener($service))->handle(new Event());
	}

	private function client(?\Throwable $failure = null, int $status = 204, array &$calls = []): TuandikianeClient {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnMap([
			['libresign', 'whatsapp_api_url', '', false, 'http://payments.test/'],
			['libresign', 'whatsapp_api_key', '', false, 'partner-key'],
		]);
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$http = $this->createMock(IClient::class);
		$http->method('post')->willReturnCallback(static function (string $url, array $options) use ($failure, $response, &$calls): IResponse {
			$calls[] = [$url, $options];
			if ($failure !== null) {
				throw $failure;
			}
			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($http);
		return new TuandikianeClient($appConfig, $clients, $this->createMock(LoggerInterface::class));
	}

	public function testAnEventGoesToTuandikianeWithThePartnerKey(): void {
		$calls = [];
		$this->client(calls: $calls)->event('draft-uuid', 'signed', 's2');

		[$url, $options] = $calls[0];
		$this->assertSame('http://payments.test/external/tuandikiane/events', $url);
		$this->assertSame('partner-key', $options['headers']['X-API-Key']);
		$this->assertSame(['draft_uuid' => 'draft-uuid', 'event' => 'signed', 'signer' => 's2'], $options['json']);
	}

	public function testOnlyASignatureNamesItsSigner(): void {
		$calls = [];
		$this->client(calls: $calls)->event('draft-uuid', 'completed');

		$this->assertSame(['draft_uuid' => 'draft-uuid', 'event' => 'completed'], $calls[0][1]['json']);
	}

	public function testTuandikianeBeingDownOrRefusingNeverThrows(): void {
		$this->client(new \RuntimeException('Connection refused'))->event('draft-uuid', 'sent');
		$this->client(status: 404)->event('draft-uuid', 'sent');
		$this->addToAssertionCount(1);
	}
}
