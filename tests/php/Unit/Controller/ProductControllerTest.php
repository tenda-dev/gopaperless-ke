<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\ProductController;
use OCA\Libresign\Db\Product;
use OCA\Libresign\Service\Product\ProductService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProductControllerTest extends TestCase {
	private ProductService&MockObject $products;
	private ProductController $controller;

	protected function setUp(): void {
		$this->products = $this->createMock(ProductService::class);
		$this->controller = new ProductController(
			$this->createMock(IRequest::class),
			$this->products,
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * Whoever can change a product sets the price everyone pays. Only reading a
	 * price, which the payment screen does, is open to non-admins.
	 */
	public function testOnlyReadingIsOpenToNonAdmins(): void {
		$open = [];
		foreach ((new \ReflectionClass(ProductController::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			// Nextcloud's own preflightedCors is inherited and open by design.
			if ($method->getDeclaringClass()->getName() === ProductController::class
				&& $method->getAttributes(NoAdminRequired::class) !== []) {
				$open[] = $method->getName();
			}
		}
		sort($open);

		$this->assertSame(['getByCode', 'list'], $open);
	}

	public function testRefusesAnUnknownCode(): void {
		$this->products->expects($this->never())->method('create');

		$response = $this->controller->create('SIGN_DOCUMNT', 'Sign', 100, 1, 'KES');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('SIGN_DOCUMENT', $response->getData()['error']);
	}

	public function testStoresTheCodePaymentsLookUp(): void {
		$this->products->expects($this->once())
			->method('create')
			->with($this->callback(static fn (Product $product): bool => $product->getCode() === 'SIGN_DOCUMENT'))
			->willReturnArgument(0);

		$response = $this->controller->create(' sign_document ', 'Sign', 100, 1, 'KES');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}
}
