<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Controller\OperationsAnalyticsController;
use OCA\Libresign\Service\Operations\Analytics\OperationsAccessService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Phase 1 authorization matrix for the Operations Analytics endpoints:
 * admin / configured-group member / outsider / anonymous / flag-disabled,
 * exercised against the real OperationsAccessService with mocked Nextcloud
 * config and group services.
 */
final class OperationsAnalyticsControllerTest extends TestCase {
	private const ENDPOINTS = ['overview', 'timeseries', 'funnel', 'payments', 'entitlements'];

	private IAppConfig&MockObject $appConfig;
	private IGroupManager&MockObject $groupManager;
	private IUserSession&MockObject $userSession;
	private OperationsAnalyticsController $controller;

	public function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new OperationsAnalyticsController(
			$this->createMock(IRequest::class),
			$this->userSession,
			new OperationsAccessService($this->appConfig, $this->groupManager),
		);
	}

	/**
	 * @param list<string> $operationsGroups value stored in operations_groups
	 */
	private function configure(bool $enabled, array $operationsGroups = ['admin']): void {
		$this->appConfig->method('getValueBool')
			->willReturnCallback(fn (string $app, string $key, bool $default = false): bool
				=> $app === Application::APP_ID && $key === 'operations_dashboard_enabled' ? $enabled : $default);
		$this->appConfig->method('getValueArray')
			->willReturnCallback(fn (string $app, string $key, array $default = []): array
				=> $app === Application::APP_ID && $key === 'operations_groups' ? $operationsGroups : $default);
	}

	/**
	 * @param list<string> $groupIds
	 */
	private function actingAs(string $uid, bool $isAdmin, array $groupIds = []): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($isAdmin);
		$this->groupManager->method('getUserGroupIds')->willReturn($groupIds);
	}

	public static function endpointProvider(): array {
		return array_combine(self::ENDPOINTS, array_map(static fn (string $e): array => [$e], self::ENDPOINTS));
	}

	#[DataProvider('endpointProvider')]
	public function testAdminIsAllowed(string $endpoint): void {
		$this->configure(true);
		$this->actingAs('root', true, ['admin']);

		$response = $this->controller->$endpoint();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['meta']['placeholder']);
	}

	#[DataProvider('endpointProvider')]
	public function testConfiguredGroupMemberIsAllowed(string $endpoint): void {
		$this->configure(true, ['admin', 'gopaperless-operations']);
		$this->actingAs('ops-user', false, ['staff', 'gopaperless-operations']);

		$this->assertSame(Http::STATUS_OK, $this->controller->$endpoint()->getStatus());
	}

	#[DataProvider('endpointProvider')]
	public function testOutsiderIsForbidden(string $endpoint): void {
		$this->configure(true, ['admin', 'gopaperless-operations']);
		$this->actingAs('bob', false, ['staff']);

		$response = $this->controller->$endpoint();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Forbidden'], $response->getData());
	}

	#[DataProvider('endpointProvider')]
	public function testAnonymousIsForbidden(string $endpoint): void {
		$this->configure(true);
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->$endpoint()->getStatus());
	}

	#[DataProvider('endpointProvider')]
	public function testFlagDisabledForbidsEvenAdminAndGroupMember(string $endpoint): void {
		$this->configure(false, ['admin', 'gopaperless-operations']);
		$this->actingAs('root', true, ['admin', 'gopaperless-operations']);

		$response = $this->controller->$endpoint();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Forbidden'], $response->getData());
	}

	/**
	 * The framework (not the controller) rejects anonymous callers and applies
	 * rate limits, so pin the attributes that make that so: NoAdminRequired lets
	 * non-admin group members in, UserRateLimit throttles, ApiRoute exposes GET.
	 */
	#[DataProvider('endpointProvider')]
	public function testEndpointAttributes(string $endpoint): void {
		$method = new \ReflectionMethod(OperationsAnalyticsController::class, $endpoint);

		$this->assertCount(1, $method->getAttributes(NoAdminRequired::class));
		$this->assertCount(1, $method->getAttributes(UserRateLimit::class));
		$routes = $method->getAttributes(ApiRoute::class);
		$this->assertCount(1, $routes);
		$route = $routes[0]->newInstance();
		$this->assertSame('GET', $route->getVerb());
		$this->assertSame('/api/{apiVersion}/operations/' . $endpoint, $route->getUrl());
	}
}
