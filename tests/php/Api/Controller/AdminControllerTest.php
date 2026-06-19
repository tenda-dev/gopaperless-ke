<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020-2024 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Api\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Tests\Api\ApiTestCase;
use OCP\IAppConfig;
use OCP\Server;

/**
 * @group DB
 */
final class AdminControllerTest extends ApiTestCase {
	/**
	 * @runInSeparateProcess
	 */
	public function testLoadCertificate():void {
		$this->createAccount('admintest', 'password', 'admin');
		$this->request
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('admintest:password')
			])
			->withPath('/api/v1/admin/certificate');

		$this->assertRequest();
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testGenerateCertificateWithFailure():void {
		// Configure request
		$this->createAccount('admintest', 'password', 'admin');
		$this->request
			->withMethod('POST')
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('admintest:password'),
				'Content-Type' => 'application/json'
			])
			->withPath('/api/v1/admin/certificate/openssl')
			->withRequestBody([
				'rootCert' => [
					'commonName' => 'CommonName',
					'names' => [
						'Invalid' => ['value' => 'BR'],
					],
				],
				'configPath' => ''
			])
			->assertResponseCode(401);

		// Make and test request mach with schema
		$this->assertRequest();
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testSetTsaConfigSensitivePassword(): void {
		$this->createAccount('admintest', 'password', 'admin');

		$this->request
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('admintest:password')
			])
			->withPath('/api/v1/admin/tsa')
			->withMethod('POST')
			->withRequestBody([
				'tsa_url' => 'https://tsa.example.com',
				'tsa_auth_type' => 'basic',
				'tsa_username' => 'testuser',
				'tsa_password' => 'secret_password'
			])
			->assertResponseCode(200);

		$this->assertRequest();
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testSetTsaConfigWithoutUrlDoesNothing(): void {
		$this->createAccount('admintest', 'password', 'admin');

		$this->request
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('admintest:password')
			])
			->withPath('/api/v1/admin/tsa')
			->withMethod('POST')
			->withRequestBody([
				'tsa_password' => 'secret_password'
			])
			->assertResponseCode(200);

		$this->assertRequest();
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testDeleteTsaConfig(): void {
		$this->createAccount('admintest', 'password', 'admin');

		$this->request
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('admintest:password')
			])
			->withPath('/api/v1/admin/tsa')
			->withMethod('DELETE')
			->assertResponseCode(200);

		$this->assertRequest();
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testSetGroupsRequestSignConfigPersistsAsArray(): void {
		$this->createAccount('admintest', 'password', 'admin');

		$this->request
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('admintest:password'),
				'Content-Type' => 'application/json',
				'OCS-APIRequest' => 'true',
			])
			->withPath('/api/v1/admin/groups-request-sign/config')
			->withMethod('POST')
			->withRequestBody([
				'groups' => ['admin', 'editors'],
			])
			->assertResponseCode(200);

		$this->assertRequest();
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testSetGroupsRequestSignConfigWithNonAsciiGroupId(): void {
		$this->createAccount('admintest', 'password', 'admin');

		$this->request
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('admintest:password'),
				'Content-Type' => 'application/json',
				'OCS-APIRequest' => 'true',
			])
			->withPath('/api/v1/admin/groups-request-sign/config')
			->withMethod('POST')
			->withRequestBody([
				'groups' => ['admin', 'SÖ'],
			])
			->assertResponseCode(200);

		$this->assertRequest();
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testSetDarajaConfigPartialPayloadPreservesSecrets(): void {
		$this->createAccount('admintest', 'password', 'admin');

		$appConfig = Server::get(IAppConfig::class);
		$appConfig->setValueString(Application::APP_ID, 'daraja_consumer_secret', 'existing-consumer-secret', false, true);
		$appConfig->setValueString(Application::APP_ID, 'daraja_pass_key', 'existing-pass-key', false, true);

		$this->request
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('admintest:password'),
				'Content-Type' => 'application/json',
				'OCS-APIRequest' => 'true',
			])
			->withPath('/api/v1/admin/daraja-config')
			->withMethod('POST')
			->withRequestBody([
				'baseUrl' => 'https://sandbox.safaricom.co.ke',
			])
			->assertResponseCode(200);

		$this->assertRequest();

		$this->assertSame('existing-consumer-secret', $appConfig->getValueString(Application::APP_ID, 'daraja_consumer_secret'));
		$this->assertSame('existing-pass-key', $appConfig->getValueString(Application::APP_ID, 'daraja_pass_key'));
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testSetDpoConfigPartialPayloadPreservesSecrets(): void {
		$this->createAccount('admintest', 'password', 'admin');

		$appConfig = Server::get(IAppConfig::class);
		$appConfig->setValueString(Application::APP_ID, 'dpo_company_token', 'existing-company-token', false, true);

		$this->request
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('admintest:password'),
				'Content-Type' => 'application/json',
				'OCS-APIRequest' => 'true',
			])
			->withPath('/api/v1/admin/dpo-config')
			->withMethod('POST')
			->withRequestBody([
				'endpoint' => 'https://api.dpo.example.com/',
			])
			->assertResponseCode(200);

		$this->assertRequest();

		$this->assertSame('existing-company-token', $appConfig->getValueString(Application::APP_ID, 'dpo_company_token'));
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testSetDarajaConfigEmptyNonSecretDeletesKey(): void {
		$this->createAccount('admintest', 'password', 'admin');

		$appConfig = Server::get(IAppConfig::class);
		$appConfig->setValueString(Application::APP_ID, 'daraja_shortcode', '12345');

		$this->request
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('admintest:password'),
				'Content-Type' => 'application/json',
				'OCS-APIRequest' => 'true',
			])
			->withPath('/api/v1/admin/daraja-config')
			->withMethod('POST')
			->withRequestBody([
				'shortCode' => '',
			])
			->assertResponseCode(200);

		$this->assertRequest();

		$this->assertSame('', $appConfig->getValueString(Application::APP_ID, 'daraja_shortcode'));
	}
}
