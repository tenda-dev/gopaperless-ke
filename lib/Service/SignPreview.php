<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\Db\File as FileEntity;
use OCA\Libresign\Db\FileElement;
use OCA\Libresign\Db\FileElementMapper;
use OCA\Libresign\Db\SignRequest;
use OCA\Libresign\Handler\FooterHandler;
use OCA\Libresign\Handler\PdfTk\Pdf;
use OCA\Libresign\Vendor\Mpdf\Mpdf;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\ITempManager;

/**
 * The PDF a WhatsApp signer gets before the signing card: the document as it
 * stands now, earlier signatures included, with a dashed box where theirs goes.
 * The box is stamped the way LibreSign stamps its footer: mPDF draws an overlay
 * page by page, pdftk lays it on the document.
 */
class SignPreview {
	private const POINT_TO_MM = 25.4 / 72;

	public function __construct(
		private IRootFolder $root,
		private FileElementMapper $elements,
		private FooterHandler $footer,
		private Pdf $pdf,
		private ITempManager $temp,
	) {
	}

	public function pdf(FileEntity $file, SignRequest $signRequest): string {
		$node = $this->root->getUserFolder($file->getUserId())->getFirstNodeById($file->getSignedNodeId() ?? $file->getNodeId());
		if (!$node instanceof File) {
			throw new NotFoundException('The document is gone');
		}
		$boxes = array_filter(
			$this->elements->getByFileIdAndSignRequestId($file->getId(), $signRequest->getId()),
			static fn (FileElement $element) => $element->getType() === 'signature',
		);
		if ($boxes === []) {
			return $node->getContent();
		}

		$input = $this->temp->getTemporaryFile('preview.pdf');
		file_put_contents($input, $node->getContent());
		$stamp = $this->temp->getTemporaryFile('boxes.pdf');
		file_put_contents($stamp, $this->overlay($this->footer->getMetadata($node, $file)['d'], $boxes));
		return $this->pdf->applyStamp($input, $stamp);
	}

	/**
	 * One overlay page per document page, blank but for the boxes.
	 *
	 * @param list<array{w: float|int, h: float|int}> $pages in points
	 * @param FileElement[] $boxes
	 */
	private function overlay(array $pages, array $boxes): string {
		$mpdf = null;
		foreach ($pages as $index => $page) {
			$format = [$page['w'] * self::POINT_TO_MM, $page['h'] * self::POINT_TO_MM];
			$mpdf ??= new Mpdf([
				'tempDir' => $this->temp->getTempBaseDir(),
				'format' => $format,
				'margin_left' => 0,
				'margin_right' => 0,
				'margin_top' => 0,
				'margin_bottom' => 0,
				'margin_header' => 0,
				'margin_footer' => 0,
			]);
			$mpdf->AddPage(orientation: 'P', newformat: $format);
			foreach ($boxes as $box) {
				if ($box->getPage() !== $index + 1) {
					continue;
				}
				[$x, $y, $w, $h] = self::rect($page['h'], $box);
				// GoPaperless green, the brand colour the WhatsApp card uses.
				$mpdf->SetFillColor(4, 213, 109);
				$mpdf->SetAlpha(0.08);
				$mpdf->Rect($x, $y, $w, $h, 'F');
				$mpdf->SetAlpha(1);
				$mpdf->SetDrawColor(4, 213, 109);
				$mpdf->SetLineWidth(0.5);
				$mpdf->SetDash(1.6, 1.2);
				$mpdf->Rect($x, $y, $w, $h, 'D');
				$mpdf->SetDash();
			}
		}
		return $mpdf->Output('', 'S');
	}

	/**
	 * A box in mPDF's terms: millimetres from the top-left corner. LibreSign
	 * stores PDF points from the bottom-left (lly < ury).
	 *
	 * @return array{float, float, float, float} x, y, width, height
	 */
	public static function rect(float|int $pageHeight, FileElement $box): array {
		return [
			$box->getLlx() * self::POINT_TO_MM,
			($pageHeight - $box->getUry()) * self::POINT_TO_MM,
			($box->getUrx() - $box->getLlx()) * self::POINT_TO_MM,
			($box->getUry() - $box->getLly()) * self::POINT_TO_MM,
		];
	}
}
