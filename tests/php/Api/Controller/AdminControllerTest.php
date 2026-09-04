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
use OCP\IGroupManager;
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

	/**
	 * @runInSeparateProcess
	 */
	public function testSetFxConfigPersistsSecrets(): void {
		$this->createAccount('admintest', 'password', 'admin');

		$this->request
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('admintest:password'),
				'Content-Type' => 'application/json',
				'OCS-APIRequest' => 'true',
			])
			->withPath('/api/v1/admin/fx-config')
			->withMethod('POST')
			->withRequestBody([
				'exchangeRateApiKey' => 'fx-api-key',
				'openExchangeAppId' => 'open-exchange-id',
			])
			->assertResponseCode(200);

		$this->assertRequest();

		$appConfig = Server::get(IAppConfig::class);
		$this->assertSame('fx-api-key', $appConfig->getValueString(Application::APP_ID, 'fx_exchangerate_api_key'));
		$this->assertSame('open-exchange-id', $appConfig->getValueString(Application::APP_ID, 'fx_openexchange_app_id'));
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testSetAppearanceProfilesConfigSuccess(): void {
		$appConfig = Server::get(IAppConfig::class);
		$appConfig->setValueBool(Application::APP_ID, 'appearance_profiles_enabled', true);

		Server::get(IGroupManager::class)->createGroup('customers');
		$this->createAccount('admintest', 'password', 'admin');

		$this->request
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('admintest:password'),
				'Content-Type' => 'application/json',
				'OCS-APIRequest' => 'true',
			])
			->withPath('/api/v1/admin/appearance-profiles/config')
			->withMethod('POST')
			->withRequestBody([
				'profiles' => [
					'customers' => [
						'footer' => false,
						'qr' => true,
						'stamp' => [
							'enabled' => true,
							'renderMode' => 'DESCRIPTION_ONLY',
						],
						'auditInfo' => true,
					],
				],
			])
			->assertResponseCode(200);

		$this->assertRequest();

		$stored = $appConfig->getValueArray(Application::APP_ID, 'appearance_profiles');
		$this->assertArrayHasKey('customers', $stored);
		$this->assertFalse($stored['customers']['footer']);
		$this->assertSame('DESCRIPTION_ONLY', $stored['customers']['stamp']['renderMode']);
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testSetAppearanceProfilesConfigRejectsNonAdmin(): void {
		$appConfig = Server::get(IAppConfig::class);
		$appConfig->setValueBool(Application::APP_ID, 'appearance_profiles_enabled', true);

		$this->createAccount('usertest', 'password', 'testGroup');

		$this->request
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('usertest:password'),
				'Content-Type' => 'application/json',
				'OCS-APIRequest' => 'true',
			])
			->withPath('/api/v1/admin/appearance-profiles/config')
			->withMethod('POST')
			->withRequestBody([
				'profiles' => ['testGroup' => ['footer' => false]],
			])
			->assertResponseCode(403);

		$this->assertRequest();
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testSetAppearanceProfilesConfigRejectsWhenFeatureDisabled(): void {
		$appConfig = Server::get(IAppConfig::class);
		$appConfig->setValueBool(Application::APP_ID, 'appearance_profiles_enabled', false);

		$this->createAccount('admintest', 'password', 'admin');

		$this->request
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('admintest:password'),
				'Content-Type' => 'application/json',
				'OCS-APIRequest' => 'true',
			])
			->withPath('/api/v1/admin/appearance-profiles/config')
			->withMethod('POST')
			->withRequestBody([
				'profiles' => ['admin' => ['footer' => false]],
			])
			->assertResponseCode(403);

		$this->assertRequest();
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testSetAppearanceProfilesConfigSkipsMalformedAndDeletedGroups(): void {
		$appConfig = Server::get(IAppConfig::class);
		$appConfig->setValueBool(Application::APP_ID, 'appearance_profiles_enabled', true);

		Server::get(IGroupManager::class)->createGroup('valid-group');
		$this->createAccount('admintest', 'password', 'admin');

		$this->request
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('admintest:password'),
				'Content-Type' => 'application/json',
				'OCS-APIRequest' => 'true',
			])
			->withPath('/api/v1/admin/appearance-profiles/config')
			->withMethod('POST')
			->withRequestBody([
				'profiles' => [
					'' => ['footer' => false],
					'deleted-group' => ['footer' => false],
					'valid-group' => ['footer' => false],
					'not-an-array' => 'invalid',
				],
			])
			->assertResponseCode(200);

		$this->assertRequest();

		$stored = $appConfig->getValueArray(Application::APP_ID, 'appearance_profiles');
		$this->assertSame(['valid-group'], array_keys($stored));
		$this->assertFalse($stored['valid-group']['footer']);
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testSignatureTextSavePersistsConfiguredMinimumSignatureDimensions(): void {
		$this->createAccount('admintest', 'password', 'admin');

		$this->request
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('admintest:password'),
				'Content-Type' => 'application/json',
				'OCS-APIRequest' => 'true',
			])
			->withPath('/api/v1/admin/signature-text')
			->withMethod('POST')
			->withRequestBody([
				'template' => 'Signed with LibreSign',
				'signatureMinimumWidth' => 180,
				'signatureMinimumHeight' => 50,
			])
			->assertResponseCode(200);

		$this->assertRequest();

		$appConfig = Server::get(IAppConfig::class);
		$this->assertSame(180.0, $appConfig->getValueFloat(Application::APP_ID, 'signature_minimum_width'));
		$this->assertSame(50.0, $appConfig->getValueFloat(Application::APP_ID, 'signature_minimum_height'));
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testSignatureTextSaveWithoutMinimumDimensionsRemainsBackwardsCompatible(): void {
		$this->createAccount('admintest', 'password', 'admin');

		$this->request
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('admintest:password'),
				'Content-Type' => 'application/json',
				'OCS-APIRequest' => 'true',
			])
			->withPath('/api/v1/admin/signature-text')
			->withMethod('POST')
			->withRequestBody([
				'template' => 'Signed with LibreSign',
			])
			->assertResponseCode(200);

		$this->assertRequest();

		$appConfig = Server::get(IAppConfig::class);
		$this->assertSame(220.0, $appConfig->getValueFloat(Application::APP_ID, 'signature_minimum_width'));
		$this->assertSame(70.0, $appConfig->getValueFloat(Application::APP_ID, 'signature_minimum_height'));
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testSignatureTextSavePersistsMinimumSignatureEnabledFlag(): void {
		$this->createAccount('admintest', 'password', 'admin');

		$this->request
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('admintest:password'),
				'Content-Type' => 'application/json',
				'OCS-APIRequest' => 'true',
			])
			->withPath('/api/v1/admin/signature-text')
			->withMethod('POST')
			->withRequestBody([
				'template' => 'Signed with LibreSign',
				'signatureMinimumEnabled' => true,
			])
			->assertResponseCode(200);

		$this->assertRequest();

		$appConfig = Server::get(IAppConfig::class);
		$this->assertTrue($appConfig->getValueBool(Application::APP_ID, 'signature_minimum_enabled'));
	}
}
