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
			'Chrome on Android signs here' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36', null],
			'Chrome on iPhone signs here' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 26_0_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/141.0.0.0 Mobile/15E148 Safari/604.1', null],
			'Safari on iPhone signs here' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 26_0_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0.1 Mobile/15E148 Safari/604.1', null],
			'no user agent signs here' => ['', null],
		];
	}

	#[DataProvider('userAgents')]
	public function testLinksOutOnlyFromBrowsersThatCantUsePasskeys(string $userAgent, ?array $expected): void {
		$this->assertSame($expected, WhatsAppSignController::browserLinks($userAgent, self::PAGE));
	}
}
