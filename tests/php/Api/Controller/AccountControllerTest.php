<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020-2024 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Api\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Tests\Api\ApiTestCase;

/**
 * @group DB
 */
final class AccountControllerTest extends ApiTestCase {
	/**
	 * @runInSeparateProcess
	 */
	public function testAccountCreateWithInvalidUuid():void {
		$this->createAccount('username', 'password');

		$this->request
			->withMethod('POST')
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('username:password'),
				'Content-Type' => 'application/json'
			])
			->withRequestBody([
				'email' => 'testuser01@test.coop',
				'password' => 'secret',
				'signPassword' => 'secretToSign'
			])
			->withPath('/api/v1/account/create/1234564789')
			->assertResponseCode(422);

		$response = $this->assertRequest();
		$body = json_decode($response->getBody()->getContents(), true);
		$this->assertEquals('Invalid UUID', $body['ocs']['data']['message']);
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testMeWithoutAuthenticatedUser():void {
		$this->request
			->withPath('/api/v1/account/me')
			->assertResponseCode(404);

		$this->assertRequest();
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testMeWithAuthenticatedUser():void {
		$this->createAccount('username', 'password');
		$this->request
			->withPath('/api/v1/account/me')
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('username:password')
			]);

		$this->assertRequest();
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testCreateOnlyReturnsNotFoundWhenDisabled(): void {
		$this->getMockAppConfig()->setValueBool(Application::APP_ID, 'public_account_creation_enabled', false);

		$this->request
			->withMethod('POST')
			->withRequestHeader([
				'Content-Type' => 'application/json',
			])
			->withRequestBody([
				'email' => 'disabledcreate@test.coop',
				'password' => 'secret',
			])
			->withPath('/api/v1/account/create-only')
			->assertResponseCode(404);

		$this->assertRequest();
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testCreateOnlyCreatesAccountWhenEnabled(): void {
		$this->getMockAppConfig()->setValueBool(Application::APP_ID, 'public_account_creation_enabled', true);

		$this->request
			->withMethod('POST')
			->withRequestHeader([
				'Content-Type' => 'application/json',
			])
			->withRequestBody([
				'email' => 'newcreateonly@test.coop',
				'password' => 'secret',
			])
			->withPath('/api/v1/account/create-only')
			->assertResponseCode(200);

		$response = $this->assertRequest();
		$body = json_decode($response->getBody()->getContents(), true);
		$this->assertEquals('newcreateonly@test.coop', $body['ocs']['data']['email']);
		$this->assertNotEmpty($body['ocs']['data']['uid']);
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testAcceptTermsReturnsNotFoundWhenDisabled(): void {
		$this->createAccount('accepttermsuser', 'password');
		$this->getMockAppConfig()->setValueBool(Application::APP_ID, 'public_accept_terms_enabled', false);

		$this->request
			->withMethod('POST')
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('accepttermsuser:password'),
				'Content-Type' => 'application/json',
			])
			->withRequestBody([
				'userId' => 'accepttermsuser',
			])
			->withPath('/api/v1/account/accept-terms')
			->assertResponseCode(404);

		$this->assertRequest();
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testAcceptTermsAcceptsForOwnAccountWhenEnabled(): void {
		$this->createAccount('accepttermsown', 'password');
		$this->getMockAppConfig()->setValueBool(Application::APP_ID, 'public_accept_terms_enabled', true);

		$this->request
			->withMethod('POST')
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('accepttermsown:password'),
				'Content-Type' => 'application/json',
			])
			->withRequestBody([
				'userId' => 'accepttermsown',
			])
			->withPath('/api/v1/account/accept-terms')
			->assertResponseCode(200);

		$response = $this->assertRequest();
		$body = json_decode($response->getBody()->getContents(), true);
		$this->assertEquals('accepttermsown', $body['ocs']['data']['uid']);
	}
}
