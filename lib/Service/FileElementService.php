<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020-2024 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\Db\File;
use OCA\Libresign\Db\FileElement;
use OCA\Libresign\Db\FileElementMapper;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\ResponseDefinitions;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * @psalm-import-type LibresignVisibleElement from ResponseDefinitions
 */
class FileElementService {
	public function __construct(
		private FileMapper $fileMapper,
		private FileElementMapper $fileElementMapper,
		private ITimeFactory $timeFactory,
		private SignatureTextService $signatureTextService,
	) {
	}

	public function saveVisibleElement(array $element): FileElement {
		$fileElement = $this->getVisibleElementFromProperties($element);
		if ($fileElement->getId()) {
			$this->fileElementMapper->update($fileElement);
		} else {
			$this->fileElementMapper->insert($fileElement);
		}
		return $fileElement;
	}

	private function getVisibleElementFromProperties(array $properties): FileElement {
		if (!empty($properties['elementId'])) {
			$fileElement = $this->fileElementMapper->getById($properties['elementId']);
		} else {
			$fileElement = new FileElement();
			$fileElement->setCreatedAt($this->timeFactory->getDateTime());
		}
		$file = null;
		if (!empty($properties['uuid'])) {
			$file = $this->fileMapper->getByUuid($properties['uuid']);
			$fileElement->setFileId($file->getId());
		} elseif (!empty($properties['fileId'])) {
			$file = $this->fileMapper->getById($properties['fileId']);
			$fileElement->setFileId($properties['fileId']);
		}
		if (!$file) {
			throw new \InvalidArgumentException('File not found for visible element');
		}
		$coordinates = $this->translateCoordinatesToInternalNotation($properties, $file);

		if (($properties['type'] ?? null) === 'signature'
			&& $this->signatureTextService->getMinimumSignatureEnabled()) {
			$coordinates = $this->enforceMinimumSignatureDimensions($coordinates, $file);
		}

		$fileElement->setSignRequestId($properties['signRequestId']);
		$fileElement->setType($properties['type']);
		$fileElement->setPage($coordinates['page']);
		$fileElement->setUrx($coordinates['urx']);
		$fileElement->setUry($coordinates['ury']);
		$fileElement->setLlx($coordinates['llx']);
		$fileElement->setLly($coordinates['lly']);
		$fileElement->setMetadata($properties['metadata'] ?? null);
		return $fileElement;
	}

	private function translateCoordinatesToInternalNotation(array $properties, File $file): array {
		$translated['page'] = $properties['coordinates']['page'] ?? 1;
		$metadata = $file->getMetadata();
		$dimension = $metadata['d'][$translated['page'] - 1];

		if (isset($properties['coordinates']['ury'])) {
			$translated['ury'] = $properties['coordinates']['ury'];
		} elseif (isset($properties['coordinates']['top'])) {
			$translated['ury'] = $dimension['h'] - $properties['coordinates']['top'];
		} else {
			$translated['ury'] = 0;
		}

		if (isset($properties['coordinates']['lly'])) {
			$translated['lly'] = $properties['coordinates']['lly'];
		} elseif (isset($properties['coordinates']['height'])) {
			if ($properties['coordinates']['height'] > $translated['ury']) {
				$translated['ury'] = $properties['coordinates']['height'];
				$translated['lly'] = 0;
			} else {
				$translated['lly'] = $translated['ury'] - $properties['coordinates']['height'];
			}
		} else {
			$translated['lly'] = 0;
		}

		if (isset($properties['coordinates']['llx'])) {
			$translated['llx'] = $properties['coordinates']['llx'];
		} elseif (isset($properties['coordinates']['left'])) {
			$translated['llx'] = $properties['coordinates']['left'];
		} else {
			$translated['llx'] = 0;
		}

		if (isset($properties['coordinates']['urx'])) {
			$translated['urx'] = $properties['coordinates']['urx'];
		} elseif (isset($properties['coordinates']['width'])) {
			$translated['urx'] = $translated['llx'] + $properties['coordinates']['width'];
		} else {
			$translated['urx'] = 0;
		}
		if ($translated['ury'] < $translated['lly']) {
			$temp = $translated['ury'];
			$translated['ury'] = $translated['lly'];
			$translated['lly'] = $temp;
		}
		if ($translated['urx'] < $translated['llx']) {
			$temp = $translated['urx'];
			$translated['urx'] = $translated['llx'];
			$translated['llx'] = $temp;
		}

		return $translated;
	}

	public function deleteVisibleElement(int $elementId): void {
		$fileElement = new FileElement();
		$fileElement = $fileElement->fromRow(['id' => $elementId]);
		$this->fileElementMapper->delete($fileElement);
	}

	public function deleteVisibleElements(int $fileId): void {
		$visibleElements = $this->fileElementMapper->getByFileId($fileId);
		foreach ($visibleElements as $visibleElement) {
			$this->fileElementMapper->delete($visibleElement);
		}
	}

	/**
	 * @param int[] $fileIds
	 * @return FileElement[]
	 */
	public function getByFileIds(array $fileIds): array {
		return $this->fileElementMapper->getByFileIds($fileIds);
	}

	/**
	 * Return visible elements formatted for API responses for given file and signRequestId
	 *
	 * @psalm-return list<LibresignVisibleElement>
	 */
	public function getVisibleElementsForSignRequest(File $file, int $signRequestId): array {
		$rows = $this->fileElementMapper->getByFileIdAndSignRequestId($file->getId(), $signRequestId);
		return $this->formatVisibleElements($rows, $file->getMetadata());
	}

	/**
	 * Format visible elements returned from DB rows for API responses.
	 *
	 * @param array<int, FileElement> $visibleElements Array of file elements as returned by mappers
	 * @param array $fileMetadata Metadata of the file (expects page dimensions under key 'd')
	 * @psalm-return list<LibresignVisibleElement>
	 */
	public function formatVisibleElements(array $visibleElements, array $fileMetadata = []): array {
		$result = [];
		foreach ($visibleElements as $fileElement) {
			$elementMetadata = $fileElement->getMetadata();
			$metadata = $fileMetadata ?: (is_array($elementMetadata) ? $elementMetadata : []);
			$dimension = $metadata['d'][$fileElement->getPage() - 1] ?? ['h' => 0];
			$height = (int)abs($fileElement->getUry() - $fileElement->getLly());
			$width = (int)abs($fileElement->getUrx() - $fileElement->getLlx());
			$top = (int)abs($dimension['h'] - $fileElement->getUry());
			$left = (int)$fileElement->getLlx();
			$result[] = [
				'elementId' => $fileElement->getId(),
				'signRequestId' => $fileElement->getSignRequestId(),
				'fileId' => $fileElement->getFileId(),
				'type' => $fileElement->getType(),
				'coordinates' => [
					'page' => $fileElement->getPage(),
					'urx' => $fileElement->getUrx(),
					'ury' => $fileElement->getUry(),
					'llx' => (int)$fileElement->getLlx(),
					'lly' => (int)$fileElement->getLly(),
					'left' => $left,
					'top' => $top,
					'width' => $width,
					'height' => $height,
				],
			];
		}
		return $result;
	}

	/**
	 * Enforces minimum signature dimensions while keeping the element on the page.
	 */
	private function enforceMinimumSignatureDimensions(array $coordinates, File $file): array {
		// Use integer coordinates to match FileElement persistence.
		$minimumWidth = (int)ceil($this->signatureTextService->getMinimumSignatureWidth());
		$minimumHeight = (int)ceil($this->signatureTextService->getMinimumSignatureHeight());

		$dimension = $file->getMetadata()['d'][$coordinates['page'] - 1];
		$pageWidth = (int)floor((float)$dimension['w']);
		$pageHeight = (int)floor((float)$dimension['h']);

		$llx = (int)$coordinates['llx'];
		$lly = (int)$coordinates['lly'];
		$urx = (int)$coordinates['urx'];
		$ury = (int)$coordinates['ury'];

		// Width: preserve the left edge and expand to the right.
		if (($urx - $llx) < $minimumWidth) {
			$urx = $llx + $minimumWidth;

			if ($urx > $pageWidth) {
				// Keep the right edge on the page; shift left only as needed.
				$urx = $pageWidth;
				$llx = $urx - $minimumWidth;

				if ($llx < 0) {
					// Minimum width exceeds the page; constrain to page bounds.
					$llx = 0;
					$urx = $pageWidth;
				}
			}
		}

		// Height: preserve the visual top edge and expand downward.
		if (($ury - $lly) < $minimumHeight) {
			$lly = $ury - $minimumHeight;

			if ($lly < 0) {
				// Keep the bottom edge on the page; shift up only as needed.
				$lly = 0;
				$ury = $minimumHeight;

				if ($ury > $pageHeight) {
					// Minimum height exceeds the page; constrain to page bounds.
					$ury = $pageHeight;
				}
			}
		}

		$coordinates['llx'] = $llx;
		$coordinates['lly'] = $lly;
		$coordinates['urx'] = $urx;
		$coordinates['ury'] = $ury;

		return $coordinates;
	}
}
