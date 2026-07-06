<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020-2024 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Settings;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Handler\CertificateEngine\CertificateEngineFactory;
use OCA\Libresign\Service\CertificatePolicyService;
use OCA\Libresign\Service\DocMdp\ConfigService as DocMdpConfigService;
use OCA\Libresign\Service\FooterService;
use OCA\Libresign\Service\IdentifyMethodService;
use OCA\Libresign\Service\SignatureBackgroundService;
use OCA\Libresign\Service\SignatureTextService;
use OCA\Libresign\Settings\Admin;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @internal
 */
final class AdminTest extends \OCA\Libresign\Tests\Unit\TestCase {
	private Admin $admin;
	private IInitialState&MockObject $initialState;
	private IdentifyMethodService&MockObject $identifyMethodService;
	private CertificateEngineFactory&MockObject $certificateEngineFactory;
	private CertificatePolicyService&MockObject $certificatePolicyService;
	private IAppConfig&MockObject $appConfig;
	private SignatureTextService&MockObject $signatureTextService;
	private SignatureBackgroundService&MockObject $signatureBackgroundService;
	private FooterService&MockObject $footerService;
	private DocMdpConfigService&MockObject $docMdpConfigService;
	public function setUp(): void {
		$this->initialState = $this->createMock(IInitialState::class);
		$this->identifyMethodService = $this->createMock(IdentifyMethodService::class);
		$this->certificateEngineFactory = $this->createMock(CertificateEngineFactory::class);
		$this->certificatePolicyService = $this->createMock(CertificatePolicyService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->signatureTextService = $this->createMock(SignatureTextService::class);
		$this->signatureBackgroundService = $this->createMock(SignatureBackgroundService::class);
		$this->footerService = $this->createMock(FooterService::class);
		$this->docMdpConfigService = $this->createMock(DocMdpConfigService::class);
		$this->admin = new Admin(
			$this->initialState,
			$this->identifyMethodService,
			$this->certificateEngineFactory,
			$this->certificatePolicyService,
			$this->appConfig,
			$this->signatureTextService,
			$this->signatureBackgroundService,
			$this->footerService,
			$this->docMdpConfigService,
		);
	}

	public function testGetSessionReturningAppId():void {
		$this->assertEquals($this->admin->getSection(), Application::APP_ID);
	}

	public function testGetPriority():void {
		$this->assertEquals($this->admin->getPriority(), 100);
	}

	public function testGetFormProvidesFreeCreditsAndOneTimeSigningInitialState(): void {
		$provided = [];
		$this->initialState
			->method('provideInitialState')
			->willReturnCallback(function (string $key, mixed $value) use (&$provided): void {
				$provided[$key] = $value;
			});

		$engine = $this->createMock(\OCA\Libresign\Handler\CertificateEngine\IEngineHandler::class);
		$engine->method('getName')->willReturn('JSignPdf');
		$this->certificateEngineFactory->method('getEngine')->willReturn($engine);
		$this->signatureTextService->method('parse')->willReturn(['parsed' => '']);
		$this->signatureTextService->method('getDefaultTemplate')->willReturn('');
		$this->signatureTextService->method('getDefaultTemplateFontSize')->willReturn(8);
		$this->signatureTextService->method('getSignatureFontSize')->willReturn(8);
		$this->signatureTextService->method('getFullSignatureHeight')->willReturn(100);
		$this->signatureTextService->method('getFullSignatureWidth')->willReturn(200);
		$this->signatureTextService->method('getTemplateFontSize')->willReturn(8);
		$this->signatureTextService->method('getTemplate')->willReturn('');
		$this->identifyMethodService->method('getIdentifyMethodsSettings')->willReturn([]);
		$this->signatureBackgroundService->method('getSignatureBackgroundType')->willReturn('');
		$this->footerService->method('getTemplateVariablesMetadata')->willReturn([]);
		$this->footerService->method('getTemplate')->willReturn('');
		$this->footerService->method('isDefaultTemplate')->willReturn(true);
		$this->docMdpConfigService->method('getConfig')->willReturn([]);

		$this->admin->getForm();

		$this->assertArrayHasKey('free_credits_enabled', $provided);
		$this->assertArrayHasKey('free_credits_uses', $provided);
		$this->assertArrayHasKey('one_time_signing_enabled', $provided);
	}
}
