<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Service\Product;

use OCA\Libresign\Db\Product;
use OCA\Libresign\Db\ProductMapper;
use OCA\Libresign\Enum\ProductCode;
use OCP\DB\Exception;
use RuntimeException;

class ProductService
{

	private const DEFAULT_SIGN_DOCUMENT_PRICE = 8000; // as minor amount (cents)
	private const DEFAULT_CERTIFICATE_ACCESS_PRICE = 30000; // as minor amount (cents)

	private ProductMapper $productMapper;

	public function __construct(ProductMapper $productMapper)
	{
		$this->productMapper = $productMapper;
	}

	/**
	 * CRITICAL:
	 * Resolve the default product for a given code.
	 *
	 * Creates the default product with sensible defaults if it does not already exist.
	 *
	 * Used by PaymentService to determine pricing.
	 *
	 * @param string $code
	 * @return Product
	 */
	public function getDefaultByCode(string $code): Product
	{
		if ($code === '') {
			throw new RuntimeException('Product code is required');
		}

		$product = $this->productMapper->findDefaultByCode($code);

		if ($product !== null) {
			return $product;
		}

		return $this->createDefaultProduct($code);
	}

	/**
	 * Create a new product
	 * @throws Exception
	 * @throws \Exception
	 */
	public function create(Product $product): Product
	{

		$now = $this->now();

		$product->setCreatedAt($now);
		$product->setUpdatedAt($now);
		$product->validate();

		return $this->productMapper->insert($product);
	}

	/**
	 * Update an existing product
	 * @throws Exception
	 * @throws \Exception
	 */
	public function update(int $productId, bool $active, int $uses): Product
	{

		$product = $this->productMapper->findById($productId);
		if (!$product) {
			throw new RuntimeException("No product with code: {$productId}");
		}
		if (!$uses || $uses < 1) {
			throw new RuntimeException('Uses must be greater than 0');
		}
		if (!$active && $product->getIsDefault()) {
			throw new RuntimeException('Cannot deactivate default product');
		}
		$product->setActive($active);
		$product->setUses($uses);
		$product->setUpdatedAt($this->now());
		$product->validate();

		return $this->productMapper->update($product);
	}

	/**
	 * Activate / deactivate product
	 *
	 * Prevents invalid state:
	 * - cannot deactivate default product
	 * @throws Exception
	 */
	public function setActive(int $productId, bool $active): Product
	{

		$product = $this->productMapper->findById($productId);

		if (!$product) {
			throw new RuntimeException('Product not found');
		}

		if (!$active && $product->getIsDefault()) {
			throw new RuntimeException('Cannot deactivate default product');
		}

		$product->setActive($active);
		return $this->productMapper->update($product);
	}

	/**
	 * CRITICAL:
	 * Set a product as default for its code.
	 *
	 * Ensures:
	 * - only ONE default per code
	 * - default must be active
	 * @throws Exception
	 */
	public function setDefaultProduct(int $productId): Product
	{

		$product = $this->productMapper->findById($productId);

		if (!$product) {
			throw new RuntimeException('Product not found');
		}

		if (!$product->getActive()) {
			throw new RuntimeException('Cannot set inactive product as default');
		}

		$code = $product->getCode();

		// Step 1: unset existing defaults
		$products = $this->productMapper->findByCode($code);

		foreach ($products as $p) {
			if ($p->getIsDefault()) {
				$p->setIsDefault(false);
				$this->productMapper->update($p);
			}
		}

		// Step 2: set new default
		$product->setIsDefault(true);
		return $this->productMapper->update($product);
	}

	/**
	 * Fetch all products for a given code (admin UI)
	 * @throws Exception
	 */
	public function listByCode(string $code): array
	{
		return $this->productMapper->findByCode($code);
	}

	/**
	 * Fetch ALL products (admin UI)
	 * @throws Exception
	 */
	public function listAll(): array
	{
		return $this->productMapper->findAll();
	}

	/**
	 * Fetch product by ID
	 */
	public function getById(int $id): ?Product
	{
		return $this->productMapper->findById($id);
	}

	/**
	 * @throws \Exception
	 */
	private function now(): string
	{
		return (new \DateTimeImmutable(
			'now',
			new \DateTimeZone('UTC'),
		))->format(DATE_ATOM);
	}

	private function createDefaultProduct(string $code): Product
	{
		$productCode = ProductCode::tryFrom($code);

		if ($productCode === null) {
			throw new RuntimeException(
				sprintf('Unknown product code: %s', $code),
			);
		}

		$product = new Product();

		$product->setCode($productCode->value);
		$product->setName($productCode->value);

		$product->setAmount(match ($productCode) {
			ProductCode::SIGN_DOCUMENT => self::DEFAULT_SIGN_DOCUMENT_PRICE,
			ProductCode::CERTIFICATE_ACCESS => self::DEFAULT_CERTIFICATE_ACCESS_PRICE,
		});

		$product->setCurrency('KES');
		$product->setUses(1);
		$product->setActive(true);
		$product->setIsDefault(true);

		return $this->create($product);
	}
}
