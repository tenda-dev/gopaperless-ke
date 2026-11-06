<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\TuandikianeFormController;
use OCA\Libresign\Service\TuandikianeClient;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TuandikianeFormControllerTest extends TestCase {
	private const TOKEN = 'k3J9xQ2mV8pL5nR7tY1wZ4aB6cD0eF2gH4iJ6kL8mN0';
	private const REVIEW = 'https://gp.test/apps/libresign/p/t/review/7d1f2c3a-4b5e-4f60-8a9b-0c1d2e3f4a5b.mac';

	private TuandikianeClient&MockObject $client;

	protected function setUp(): void {
		$this->client = $this->createMock(TuandikianeClient::class);
	}

	private function controller(): TuandikianeFormController {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(static function (string $route, array $params): string {
			$token = $params['token'];
			unset($params['token']);
			return '/apps/libresign/p/t/form/' . $token . ($route === 'libresign.tuandikianeForm.page' && $params !== [] ? '?' . http_build_query($params) : '');
		});
		$urls->method('getAbsoluteURL')->willReturn('https://gp.test/');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new TuandikianeFormController($this->createMock(IRequest::class), $this->client, $urls, $l10n, $this->createMock(LoggerInterface::class));
	}

	private static function form(?array $prefill = null): array {
		return ['status' => 200, 'body' => [
			'templates' => [
				['id' => 'loan', 'title' => 'Loan agreement', 'description' => 'One person lends money to another', 'fields' => [
					['name' => 'lender_name', 'label' => 'Lender\'s full name', 'type' => 'text', 'required' => true],
					['name' => 'lender_phone', 'label' => 'Lender\'s WhatsApp number', 'type' => 'phone', 'required' => true],
					['name' => 'amount', 'label' => 'Amount (KES)', 'type' => 'money', 'required' => true],
					['name' => 'due', 'label' => 'Due date', 'type' => 'date', 'required' => false],
				]],
				['id' => 'sale', 'title' => 'Sale agreement', 'description' => 'One person sells something to another', 'fields' => [
					['name' => 'item', 'label' => 'What is sold', 'type' => 'textarea', 'required' => true],
				]],
			],
			'prefill' => $prefill,
		]];
	}

	public function testTheKindsOfAgreementComeFirst(): void {
		$this->client->method('form')->with(self::TOKEN)->willReturn(self::form());

		$response = $this->controller()->page(self::TOKEN);

		$this->assertSame('tuandikiane_form', $response->getTemplateName());
		$this->assertSame('pick', $response->getParams()['mode']);
		$this->assertSame(['Loan agreement', 'Sale agreement'], array_column($response->getParams()['choices'], 'title'));
		$this->assertSame('/apps/libresign/p/t/form/' . self::TOKEN . '?template=sale', $response->getParams()['choices'][1]['url']);
	}

	public function testAPickedKindShowsItsFieldsWithTheRightInputs(): void {
		$this->client->method('form')->willReturn(self::form());

		$params = $this->controller()->page(self::TOKEN, 'loan')->getParams();

		$this->assertSame('fill', $params['mode']);
		$this->assertSame(['text', 'tel', 'text', 'date'], array_column(array_column($params['fields'], 'input'), 'type'));
		$this->assertSame('numeric', $params['fields'][2]['input']['inputmode']);
		$this->assertSame('/apps/libresign/p/t/form/' . self::TOKEN . '?choose=1', $params['chooseUrl']);
	}

	public function testThePrefilledKindOpensFilledInUnlessTheyChooseAgain(): void {
		$this->client->method('form')->willReturn(self::form(['template' => 'loan', 'values' => ['lender_name' => 'Neo Kamau']]));

		$params = $this->controller()->page(self::TOKEN)->getParams();
		$this->assertSame('loan', $params['template']['id']);
		$this->assertSame('Neo Kamau', $params['fields'][0]['value']);

		$this->assertSame('pick', $this->controller()->page(self::TOKEN, '', '1')->getParams()['mode']);
	}

	public function testAnExpiredOrUnknownLinkSaysSo(): void {
		$this->client->method('form')->willReturnOnConsecutiveCalls(['status' => 410, 'body' => []], ['status' => 404, 'body' => []]);

		$this->assertSame('expired', $this->controller()->page(self::TOKEN)->getParams()['mode']);
		$this->assertSame('expired', $this->controller()->page(self::TOKEN)->getParams()['mode']);
	}

	public function testSomethingThatIsNoTokenIsNotSentOn(): void {
		$this->client->expects($this->never())->method('form');

		$this->assertSame('expired', $this->controller()->page('../../etc')->getParams()['mode']);
	}

	public function testTuandikianeBeingDownSaysTryAgain(): void {
		$this->client->method('form')->willThrowException(new \RuntimeException('Connection refused'));

		$this->assertSame('unavailable', $this->controller()->page(self::TOKEN)->getParams()['mode']);
	}

	public function testAcceptedDetailsGoStraightToTheReviewLink(): void {
		$this->client->expects($this->once())->method('submitForm')
			->with(self::TOKEN, 'loan', ['lender_name' => 'Neo Kamau', 'amount' => '5000'])
			->willReturn(['status' => 200, 'body' => ['review_url' => self::REVIEW]]);

		$response = $this->controller()->submit(self::TOKEN, 'loan', ['lender_name' => ' Neo Kamau ', 'amount' => '5000', 'bad name!' => 'x', 'nested' => ['x']]);

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame(self::REVIEW, $response->getRedirectURL());
	}

	public function testAReviewLinkToAnywhereElseIsNotFollowed(): void {
		$this->client->method('submitForm')->willReturn(['status' => 200, 'body' => ['review_url' => 'https://evil.test/apps/libresign/p/t/review/x.y']]);
		$this->client->method('form')->willReturn(self::form());

		$response = $this->controller()->submit(self::TOKEN, 'loan', ['lender_name' => 'Neo']);

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertNotSame([], $response->getParams()['formErrors']);
	}

	public function testRefusedDetailsComeBackWithEachErrorBesideItsFieldAndTheTypedValues(): void {
		$this->client->method('submitForm')->willReturn(['status' => 422, 'body' => ['errors' => [
			'lender_phone' => 'Enter a WhatsApp number, like 0712 345 678',
			'template' => 'Pick a kind of agreement',
		]]]);
		$this->client->method('form')->willReturn(self::form());

		$params = $this->controller()->submit(self::TOKEN, 'loan', ['lender_name' => 'Neo Kamau', 'lender_phone' => '0712'])->getParams();

		$this->assertSame('fill', $params['mode']);
		$this->assertSame(['Neo Kamau', '0712', '', ''], array_column($params['fields'], 'value'));
		$this->assertSame(['', 'Enter a WhatsApp number, like 0712 345 678', '', ''], array_column($params['fields'], 'error'));
		$this->assertSame(['Pick a kind of agreement'], $params['formErrors']);
	}

	public function testDetailsOnAUsedLinkSayItExpired(): void {
		$this->client->method('submitForm')->willReturn(['status' => 410, 'body' => []]);
		$this->client->expects($this->never())->method('form');

		$this->assertSame('expired', $this->controller()->submit(self::TOKEN, 'loan', [])->getParams()['mode']);
	}

	public function testTheClientUsesTheWhatsAppPartnerKeyAndSendsTheValuesAsAnObject(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnMap([
			['libresign', 'whatsapp_api_url', '', false, 'http://payments.test'],
			['libresign', 'whatsapp_api_key', '', false, 'partner-key'],
		]);
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn('{"templates": []}');
		$calls = [];
		$http = $this->createMock(IClient::class);
		$record = static function (string $url, array $options) use (&$calls, $response): IResponse {
			$calls[] = [$url, $options];
			return $response;
		};
		$http->method('get')->willReturnCallback($record);
		$http->method('post')->willReturnCallback($record);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($http);
		$client = new TuandikianeClient($appConfig, $clients, $this->createMock(LoggerInterface::class));

		$this->assertSame(['status' => 200, 'body' => ['templates' => []]], $client->form(self::TOKEN));
		$client->submitForm(self::TOKEN, 'loan', []);

		$this->assertSame('http://payments.test/external/tuandikiane/forms/' . self::TOKEN, $calls[0][0]);
		$this->assertSame('partner-key', $calls[0][1]['headers']['X-API-Key']);
		$this->assertSame('{"template":"loan","values":{}}', json_encode($calls[1][1]['json']));
	}
}
