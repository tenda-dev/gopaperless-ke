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
	private const IPHONE = [
		'chrome' => 'googlechromes://gopaperless.ke/apps/libresign/p/whatsapp/uuid.mac',
		'other' => 'x-safari-https://gopaperless.ke/apps/libresign/p/whatsapp/uuid.mac',
	];
	private const ANDROID = [
		'chrome' => 'intent://gopaperless.ke/apps/libresign/p/whatsapp/uuid.mac#Intent;scheme=https;package=com.android.chrome;end',
		'other' => 'intent://gopaperless.ke/apps/libresign/p/whatsapp/uuid.mac#Intent;scheme=https;end',
	];

	public static function userAgents(): array {
		return [
			'WhatsApp on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 26_0_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0.1 Mobile/15E148 Safari/604.1 [WAiOS/2.25.31]', self::IPHONE],
			'WhatsApp on Android' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.7390.124 Mobile Safari/537.36 [WA4A/2.25.32.75;]', self::ANDROID],
			'any Android web view' => ['Mozilla/5.0 (Linux; Android 14; SM-A546E; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/141.0.0.0 Mobile Safari/537.36', self::ANDROID],
			'Android reading as plain Chrome' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36', self::ANDROID],
			'Chrome on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 26_0_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/141.0.0.0 Mobile/15E148 Safari/604.1', self::IPHONE],
			'Safari on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 26_0_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0.1 Mobile/15E148 Safari/604.1', self::IPHONE],
			'WhatsApp on an iPad reading as a Mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15 [WAiOS/2.25.31]', self::IPHONE],
			'a computer has no links' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', null],
			'no user agent has no links' => ['', null],
		];
	}

	#[DataProvider('userAgents')]
	public function testTheUserAgentOnlyPicksTheKindOfLink(string $userAgent, ?array $expected): void {
		$this->assertSame($expected, WhatsAppSignController::browserLinks($userAgent, self::PAGE));
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
