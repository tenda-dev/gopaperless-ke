<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\WhatsAppSignController;
use OCA\Libresign\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class WhatsAppSignControllerTest extends TestCase {
	private const PAGE = 'https://gopaperless.ke/apps/libresign/p/whatsapp/uuid.mac';
	private const IPHONE = 'googlechromes://gopaperless.ke/apps/libresign/p/whatsapp/uuid.mac';
	private const ANDROID = 'intent://gopaperless.ke/apps/libresign/p/whatsapp/uuid.mac#Intent;scheme=https;package=com.android.chrome;end';

	public static function userAgents(): array {
		return [
			'WhatsApp on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 26_0_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0.1 Mobile/15E148 Safari/604.1 [WAiOS/2.25.31]', true, self::IPHONE],
			'WhatsApp on a real Samsung' => ['Mozilla/5.0 (Linux; Android 12; SM-G990U3 Build/SP1A.210812.016) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/156.0.8078.47 Mobile Safari/537.36 WA4A/2.26.42.74', true, self::ANDROID],
			'any Android web view' => ['Mozilla/5.0 (Linux; Android 14; SM-A546E; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/141.0.0.0 Mobile Safari/537.36', true, self::ANDROID],
			'WhatsApp on an iPad reading as a Mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15 [WAiOS/2.25.31]', true, self::IPHONE],
			'Chrome on Android' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36', false, self::ANDROID],
			'Chrome on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 26_0_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/141.0.0.0 Mobile/15E148 Safari/604.1', false, self::IPHONE],
			'Safari on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 26_0_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0.1 Mobile/15E148 Safari/604.1', false, self::IPHONE],
			'a computer has no link' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', false, null],
			'no user agent has no link' => ['', false, null],
		];
	}

	#[DataProvider('userAgents')]
	public function testTheUserAgentCatchesInAppBrowsersAndPicksTheLink(string $userAgent, bool $inApp, ?string $link): void {
		$this->assertSame($inApp, WhatsAppSignController::isInAppBrowser($userAgent));
		$this->assertSame($link, WhatsAppSignController::chromeLink($userAgent, self::PAGE));
	}

	public static function handOffPaths(): array {
		return [
			'the receipt\'s View Document' => ['/apps/libresign/f/validation/8c1e2f6a-3b7d-4c9e-8a21-5d6f7e8a9b0c', true],
			'Sign another document' => ['/apps/libresign/f/request', true],
			'nothing' => ['', false],
			'another app, such as a web view logging in' => ['/login/flow', false],
			'another site' => ['https://evil.example/apps/libresign/f/request', false],
			'another site, no scheme' => ['//evil.example/apps/libresign/f/request', false],
			'adding to the intent link' => ['/apps/libresign/x#Intent;package=evil;end', false],
			'adding to the intent link, no #' => ['/apps/libresign/x;package=evil;end', false],
			'a query' => ['/apps/libresign/x?y=1', false],
			'climbing out' => ['/apps/libresign/../../login', false],
			'a trailing newline' => ["/apps/libresign/f/request\n", false],
		];
	}

	#[DataProvider('handOffPaths')]
	public function testHandsOffOnlyOurOwnPlainPaths(string $path, bool $expected): void {
		$this->assertSame($expected, WhatsAppSignController::isHandOffPath($path));
	}
}
