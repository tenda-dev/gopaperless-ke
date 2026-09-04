<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Db\File;
use OCA\Libresign\Db\FileElement;
use OCA\Libresign\Db\FileElementMapper;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Service\FileElementService;
use OCA\Libresign\Service\SignatureTextService;
use OCA\Libresign\Tests\Unit\TestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;

final class FileElementServiceTest extends TestCase {
	private function getService(
		?FileMapper $fileMapper = null,
		?FileElementMapper $fileElementMapper = null,
		?SignatureTextService $signatureTextService = null,
	): FileElementService {
		$fileMapper ??= $this->createMock(FileMapper::class);
		$fileElementMapper ??= $this->createMock(FileElementMapper::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$signatureTextService ??= $this->createConfiguredMock(SignatureTextService::class, [
			'getMinimumSignatureEnabled' => true,
			'getMinimumSignatureWidth' => (float)SignatureTextService::MINIMUM_SIGNATURE_WIDTH,
			'getMinimumSignatureHeight' => (float)SignatureTextService::MINIMUM_SIGNATURE_HEIGHT,
		]);

		return new FileElementService($fileMapper, $fileElementMapper, $timeFactory, $signatureTextService);
	}

	private function makeFileWithPageDimensions(array $pageDimension): File {
		$file = new File();
		$file->setId(1);
		$file->setMetadata(['d' => [$pageDimension]]);
		return $file;
	}

	#[DataProvider('dataFormatVisibleElements')]
	public function testFormatVisibleElements(array $visibleElements, array $expectedChecks): void {
		$service = $this->getService();

		$fileElements = array_map(function ($data) {
			$element = new FileElement();
			$element->setId($data['id']);
			$element->setSignRequestId($data['sign_request_id']);
			$element->setType($data['type']);
			$element->setFileId($data['file_id']);
			$element->setPage($data['page']);
			$element->setUrx((int)$data['urx']);
			$element->setUry((int)$data['ury']);
			$element->setLlx((int)$data['llx']);
			$element->setLly((int)$data['lly']);
			$element->setMetadata($data['metadata']);
			return $element;
		}, $visibleElements);

		$result = $service->formatVisibleElements($fileElements);

		$this->assertIsArray($result);

		foreach ($expectedChecks as $index => $checks) {
			$this->assertArrayHasKey($index, $result);
			$coords = $result[$index]['coordinates'];
			foreach ($checks as $key => $value) {
				$this->assertEquals($value, $coords[$key], "unexpected {$key} for element {$index}");
			}
		}
	}

	public static function dataFormatVisibleElements(): array {
		return [
			'single with string coords' => [
				[
					[
						'id' => 123,
						'sign_request_id' => 45,
						'type' => 'signature',
						'file_id' => 67,
						'page' => 2,
						'urx' => '300',
						'ury' => '400',
						'llx' => '100',
						'lly' => '200',
						'metadata' => [ 'd' => [ ['w' => 0, 'h' => 800], ['w' => 0, 'h' => 900] ] ],
					],
				],
				[
					0 => [ 'page' => 2, 'urx' => 300, 'ury' => 400, 'llx' => 100, 'lly' => 200, 'left' => 100, 'top' => 500, 'width' => 200, 'height' => 200 ],
				],
			],
			'multiple elements different sizes' => [
				[
					[
						'id' => 1,
						'sign_request_id' => 10,
						'type' => 'text',
						'file_id' => 5,
						'page' => 1,
						'urx' => 50,
						'ury' => 150,
						'llx' => 10,
						'lly' => 100,
						'metadata' => [ 'd' => [ ['w' => 0, 'h' => 200] ] ],
					],
					[
						'id' => 2,
						'sign_request_id' => 11,
						'type' => 'checkbox',
						'file_id' => 5,
						'page' => 1,
						'urx' => 120,
						'ury' => 180,
						'llx' => 100,
						'lly' => 160,
						'metadata' => [ 'd' => [ ['w' => 0, 'h' => 200] ] ],
					],
				],
				[
					0 => [ 'page' => 1, 'width' => 40, 'height' => 50 ],
					1 => [ 'page' => 1, 'width' => 20, 'height' => 20 ],
				],
			],
			'no metadata fallback uses given dimension' => [
				[
					[
						'id' => 9,
						'sign_request_id' => 99,
						'type' => 'stamp',
						'file_id' => 8,
						'page' => 1,
						'urx' => 200,
						'ury' => 300,
						'llx' => 50,
						'lly' => 100,
						'metadata' => [ 'd' => [ ['w' => 0, 'h' => 350] ] ],
					],
				],
				[
					0 => [ 'page' => 1, 'width' => 150, 'height' => 200, 'top' => 50 ],
				],
			],
		];
	}

	#[DataProvider('dataEnforceMinimumSignatureDimensions')]
	public function testSaveVisibleElementEnforcesMinimumSignatureDimensions(
		string $type,
		array $coordinates,
		array $pageDimension,
		float $minimumWidth,
		float $minimumHeight,
		array $expected,
	): void {
		$file = $this->makeFileWithPageDimensions($pageDimension);
		$fileMapper = $this->createMock(FileMapper::class);
		$fileMapper->method('getById')->willReturn($file);

		$fileElementMapper = $this->createMock(FileElementMapper::class);
		$fileElementMapper->expects($this->once())->method('insert');

		$signatureTextService = $this->createConfiguredMock(SignatureTextService::class, [
			'getMinimumSignatureEnabled' => true,
			'getMinimumSignatureWidth' => $minimumWidth,
			'getMinimumSignatureHeight' => $minimumHeight,
		]);

		$service = $this->getService($fileMapper, $fileElementMapper, $signatureTextService);

		$result = $service->saveVisibleElement([
			'fileId' => 1,
			'signRequestId' => 10,
			'type' => $type,
			'coordinates' => $coordinates,
		]);

		$this->assertEquals($expected['llx'], $result->getLlx(), 'unexpected llx');
		$this->assertEquals($expected['lly'], $result->getLly(), 'unexpected lly');
		$this->assertEquals($expected['urx'], $result->getUrx(), 'unexpected urx');
		$this->assertEquals($expected['ury'], $result->getUry(), 'unexpected ury');

		// Required invariant: the final rectangle must remain inside the page.
		$this->assertGreaterThanOrEqual(0, $result->getLlx());
		$this->assertGreaterThanOrEqual(0, $result->getLly());
		$this->assertLessThanOrEqual($pageDimension['w'], $result->getUrx());
		$this->assertLessThanOrEqual($pageDimension['h'], $result->getUry());
		$this->assertLessThanOrEqual($result->getUrx(), $result->getLlx());
		$this->assertLessThanOrEqual($result->getUry(), $result->getLly());
	}

	public static function dataEnforceMinimumSignatureDimensions(): array {
		return [
			'signature below minimum width only' => [
				'signature',
				['page' => 1, 'llx' => 100, 'lly' => 500, 'urx' => 150, 'ury' => 600],
				['w' => 600, 'h' => 800],
				220.0,
				70.0,
				['llx' => 100, 'lly' => 500, 'urx' => 320, 'ury' => 600],
			],
			'signature below minimum height only' => [
				'signature',
				['page' => 1, 'llx' => 100, 'lly' => 590, 'urx' => 350, 'ury' => 600],
				['w' => 600, 'h' => 800],
				220.0,
				70.0,
				['llx' => 100, 'lly' => 530, 'urx' => 350, 'ury' => 600],
			],
			'signature below both minimum width and height' => [
				'signature',
				['page' => 1, 'llx' => 100, 'lly' => 590, 'urx' => 140, 'ury' => 600],
				['w' => 600, 'h' => 800],
				220.0,
				70.0,
				['llx' => 100, 'lly' => 530, 'urx' => 320, 'ury' => 600],
			],
			'signature exactly at minimum remains unchanged' => [
				'signature',
				['page' => 1, 'llx' => 100, 'lly' => 530, 'urx' => 320, 'ury' => 600],
				['w' => 600, 'h' => 800],
				220.0,
				70.0,
				['llx' => 100, 'lly' => 530, 'urx' => 320, 'ury' => 600],
			],
			'signature above minimum remains unchanged' => [
				'signature',
				['page' => 1, 'llx' => 100, 'lly' => 500, 'urx' => 400, 'ury' => 600],
				['w' => 600, 'h' => 800],
				220.0,
				70.0,
				['llx' => 100, 'lly' => 500, 'urx' => 400, 'ury' => 600],
			],
			'non-signature element remains unaffected' => [
				'text',
				['page' => 1, 'llx' => 100, 'lly' => 590, 'urx' => 140, 'ury' => 600],
				['w' => 600, 'h' => 800],
				220.0,
				70.0,
				['llx' => 100, 'lly' => 590, 'urx' => 140, 'ury' => 600],
			],
			'configured minimum different from constants is used' => [
				'signature',
				['page' => 1, 'llx' => 100, 'lly' => 580, 'urx' => 150, 'ury' => 600],
				['w' => 600, 'h' => 800],
				100.0,
				30.0,
				['llx' => 100, 'lly' => 570, 'urx' => 200, 'ury' => 600],
			],
			'signature near right page boundary clamps urx to page width' => [
				'signature',
				['page' => 1, 'llx' => 250, 'lly' => 300, 'urx' => 260, 'ury' => 370],
				['w' => 300, 'h' => 400],
				220.0,
				70.0,
				['llx' => 80, 'lly' => 300, 'urx' => 300, 'ury' => 370],
			],
			'signature near bottom page boundary clamps lly to zero' => [
				'signature',
				['page' => 1, 'llx' => 50, 'lly' => 40, 'urx' => 300, 'ury' => 50],
				['w' => 300, 'h' => 400],
				220.0,
				70.0,
				['llx' => 50, 'lly' => 0, 'urx' => 300, 'ury' => 70],
			],
			'signature near left and top boundaries preserves existing position' => [
				'signature',
				['page' => 1, 'llx' => 0, 'lly' => 330, 'urx' => 10, 'ury' => 400],
				['w' => 300, 'h' => 400],
				220.0,
				70.0,
				['llx' => 0, 'lly' => 330, 'urx' => 220, 'ury' => 400],
			],
			'signature undersized in both dims in the bottom-right corner shifts left and up' => [
				'signature',
				['page' => 1, 'llx' => 250, 'lly' => 40, 'urx' => 260, 'ury' => 50],
				['w' => 300, 'h' => 400],
				220.0,
				70.0,
				['llx' => 80, 'lly' => 0, 'urx' => 300, 'ury' => 70],
			],
			'fractional page dimensions keep integer invariants' => [
				'signature',
				['page' => 1, 'llx' => 500, 'lly' => 700, 'urx' => 560, 'ury' => 720],
				['w' => 611.976, 'h' => 791.976],
				220.0,
				70.0,
				['llx' => 391, 'lly' => 650, 'urx' => 611, 'ury' => 720],
			],
			'pathological minimum larger than page does not produce out-of-page coordinates' => [
				'signature',
				['page' => 1, 'llx' => 100, 'lly' => 90, 'urx' => 150, 'ury' => 100],
				['w' => 300, 'h' => 400],
				500.0,
				500.0,
				['llx' => 0, 'lly' => 0, 'urx' => 300, 'ury' => 400],
			],
		];
	}

	public function testSaveVisibleElementDoesNotEnforceWhenMinimumDisabled(): void {
		$file = $this->makeFileWithPageDimensions(['w' => 600, 'h' => 800]);
		$fileMapper = $this->createMock(FileMapper::class);
		$fileMapper->method('getById')->willReturn($file);

		$fileElementMapper = $this->createMock(FileElementMapper::class);
		$fileElementMapper->expects($this->once())->method('insert');

		$signatureTextService = $this->createConfiguredMock(SignatureTextService::class, [
			'getMinimumSignatureEnabled' => false,
			'getMinimumSignatureWidth' => 220.0,
			'getMinimumSignatureHeight' => 70.0,
		]);

		$service = $this->getService($fileMapper, $fileElementMapper, $signatureTextService);

		$result = $service->saveVisibleElement([
			'fileId' => 1,
			'signRequestId' => 10,
			'type' => 'signature',
			'coordinates' => ['page' => 1, 'llx' => 100, 'lly' => 590, 'urx' => 140, 'ury' => 600],
		]);

		// Toggle OFF: an undersized signature must be persisted exactly as submitted.
		$this->assertEquals(100, $result->getLlx());
		$this->assertEquals(590, $result->getLly());
		$this->assertEquals(140, $result->getUrx());
		$this->assertEquals(600, $result->getUry());
	}

	public function testFormatVisibleElementsDoesNotClampExistingUndersizedSignatureElement(): void {
		$service = $this->getService();

		$element = new FileElement();
		$element->setId(1);
		$element->setSignRequestId(1);
		$element->setType('signature');
		$element->setFileId(1);
		$element->setPage(1);
		$element->setUrx(140);
		$element->setUry(600);
		$element->setLlx(100);
		$element->setLly(590);
		$element->setMetadata(['d' => [['w' => 600, 'h' => 800]]]);

		$result = $service->formatVisibleElements([$element]);

		$this->assertSame(40, $result[0]['coordinates']['width']);
		$this->assertSame(10, $result[0]['coordinates']['height']);
	}
}
