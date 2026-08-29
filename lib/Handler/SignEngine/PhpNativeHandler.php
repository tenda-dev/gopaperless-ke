<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Handler\SignEngine;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Handler\CertificateEngine\CertificateEngineFactory;
use OCA\Libresign\Service\DocMdp\ConfigService as DocMdpConfigService;
use OCA\Libresign\Service\SignatureBackgroundService;
use OCA\Libresign\Service\SignatureTextService;
use OCA\Libresign\Service\SignerElementsService;
use OCP\Files\File;
use OCP\IAppConfig;
use SignerPHP\Application\DTO\CertificateCredentialsDto;
use SignerPHP\Application\DTO\CertificationLevel;
use SignerPHP\Application\DTO\PdfContentDto;
use SignerPHP\Application\DTO\SignatureActorDto;
use SignerPHP\Application\DTO\SignatureAppearanceDto;
use SignerPHP\Application\DTO\SignatureAppearanceXObjectDto;
use SignerPHP\Application\DTO\SignatureMetadataDto;
use SignerPHP\Application\DTO\SigningOptionsDto;
use SignerPHP\Application\DTO\SignPdfRequestDto;
use SignerPHP\Application\DTO\TimestampOptionsDto;
use SignerPHP\Application\Service\PdfSigningService;
use SignerPHP\Infrastructure\Legacy\OpenSslCertificateValidator;
use SignerPHP\Infrastructure\Native\NativePdfSigningEngine;

class PhpNativeHandler extends Pkcs12Handler {
	public function __construct(
		private IAppConfig $appConfig,
		private DocMdpConfigService $docMdpConfigService,
		private SignatureTextService $signatureTextService,
		private SignatureBackgroundService $signatureBackgroundService,
		protected CertificateEngineFactory $certificateEngineFactory,
	) {
	}

	#[\Override]
	public function sign(): File {
		$this->beforeSign();
		$signedContent = $this->getSignedContent();
		$this->getInputFile()->putContent($signedContent);
		return $this->getInputFile();
	}

	#[\Override]
	public function getSignedContent(): string {
		$pdfContent = $this->getInputFile()->getContent();
		$certificate = CertificateCredentialsDto::fromContent(
			$this->getCertificate(),
			$this->getPassword(),
		);
		$service = new PdfSigningService(
			new OpenSslCertificateValidator(),
			new NativePdfSigningEngine(),
		);

		$visibleElements = $this->getVisibleElements();
		$metadata = $this->buildMetadata();
		$timestamp = $this->buildTimestampOptions();
		$certificationLevel = $this->resolveCertificationLevel(empty($visibleElements));

		if (empty($visibleElements)) {
			return $service->sign(SignPdfRequestDto::fromRequired(
				new PdfContentDto($pdfContent),
				$certificate,
				new SigningOptionsDto(
					metadata: $metadata,
					timestamp: $timestamp,
					certificationLevel: $certificationLevel,
					useDefaultAppearance: false,
				),
			));
		}

		$applyOnce = $certificationLevel;
		// signer-php expects screen/top-left coords (Y=0 at top, grows downward).
		// LibreSign stores PDF bottom-left coords (Y=0 at bottom, lly < ury).
		// Conversion: screen_y = pageHeight - pdf_y
		// Page dimensions come from FileEntity::getMetadata()['d'] (0-based array of ['w','h']).
		$pageDimensions = $this->getSignatureParams()['PageDimensions'] ?? [];
		foreach ($visibleElements as $element) {
			$fileElement = $element->getFileElement();
			$llx = (float)($fileElement->getLlx() ?? 0);
			$lly = (float)($fileElement->getLly() ?? 0);
			$urx = (float)($fileElement->getUrx() ?? 0);
			$ury = (float)($fileElement->getUry() ?? 0);
			$width = (int)($urx - $llx);
			$height = (int)($ury - $lly);
			// signer-php uses 0-based page index; LibreSign stores 1-based
			$pageIndex = max(0, $fileElement->getPage() - 1);
			$pageHeight = $this->resolvePageHeight($pageDimensions, $pageIndex);
			$appearance = $this->buildAppearanceForElement(
				llx: $llx,
				lly: $lly,
				urx: $urx,
				ury: $ury,
				pageHeight: $pageHeight,
				pageIndex: $pageIndex,
				width: $width,
				height: $height,
				signatureImagePath: $element->getTempFile(),
			);
			$pdfContent = $service->sign(SignPdfRequestDto::fromRequired(
				new PdfContentDto($pdfContent),
				$certificate,
				new SigningOptionsDto(
					metadata: $metadata,
					appearance: $appearance,
					timestamp: $timestamp,
					// DocMDP only applies once (the first signature certifies)
					certificationLevel: $applyOnce,
				),
			));
			$applyOnce = null;
		}

		return $pdfContent;
	}

	private function buildAppearanceForElement(
		float $llx,
		float $lly,
		float $urx,
		float $ury,
		float $pageHeight,
		int $pageIndex,
		int $width,
		int $height,
		string $signatureImagePath = '',
	): SignatureAppearanceDto {
		$renderMode = $this->signatureTextService->getRenderMode();

		// n0 layer: background stamp is always placed full-bbox when enabled.
		$imagePath = $this->signatureBackgroundService->isEnabled()
			? $this->signatureBackgroundService->getImagePath()
			: null;
		$imagePath = $this->prepareBackgroundImage($imagePath, $width, $height);

		// GRAPHIC_AND_DESCRIPTION: user's drawn image goes into the n2 xObject layer
		// on the left half of the bbox so it does not distort or cover the description text.
		// Background (if enabled) still occupies the full n0 layer behind everything.
		// GRAPHIC_ONLY: user's drawn image occupies the full bbox in n2; no description text.
		$userImgPath = null;
		$userImgRect = null;
		if ($renderMode === SignerElementsService::RENDER_MODE_GRAPHIC_AND_DESCRIPTION) {
			if ($signatureImagePath !== '' && is_file($signatureImagePath)) {
				$userImgPath = $signatureImagePath;

				$nameAreaHeight = max(12.0, (float)$height * 0.15);
				$userImgRect = [0.0, $nameAreaHeight, (float)$width / 2.0, (float)$height];
			}
		} elseif ($renderMode === SignerElementsService::RENDER_MODE_GRAPHIC_ONLY) {
			if ($signatureImagePath !== '' && is_file($signatureImagePath)) {
				$userImgPath = $signatureImagePath;
				$userImgRect = null; // full bbox
			}
		}

		return new SignatureAppearanceDto(
			backgroundImagePath: $imagePath,
			rect: [
				$llx,
				$pageHeight - $ury,  // screen top = pageH - PDF ury
				$urx,
				$pageHeight - $lly,  // screen bottom = pageH - PDF lly
			],
			page: $pageIndex,
			xObject: $this->buildXObject($width, $height, $renderMode),
			signatureImagePath: $userImgPath,
			signatureImageFrame: $userImgRect,
		);
	}

	private function prepareBackgroundImage(?string $imagePath, int $width, int $height): ?string {
		if (!$this->signatureTextService->hasQrCodeInTemplate()) {
			return $imagePath;
		}

		$params = $this->getSignatureParams();
		$documentUuid = $params['DocumentUUID'] ?? null;
		if (empty($documentUuid) || !is_string($documentUuid)) {
			return $imagePath;
		}

		$validationUrl = $this->signatureTextService->buildValidationUrl($documentUuid);
		$base64 = $this->signatureTextService->getQrCodeImageBase64($validationUrl);
		$content = base64_decode($base64, true);
		if ($content === false) {
			return $imagePath;
		}

		$tempManager = \OCP\Server::get(\OCP\ITempManager::class);
		$qrCodePath = $tempManager->getTemporaryFile('_qrcode.png');
		if (!$qrCodePath) {
			return $imagePath;
		}
		file_put_contents($qrCodePath, $content);

		if (!extension_loaded('imagick')) {
			return $imagePath;
		}

		$canvas = new \Imagick();
		$canvas->newImage($width, $height, new \ImagickPixel('transparent'));
		$canvas->setImageFormat('png32');
		$canvas->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);

		if ($imagePath !== null && $imagePath !== '' && is_file($imagePath)) {
			$background = new \Imagick($imagePath);
			$background->setImageFormat('png');
			$background->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
			$background->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1, true);
			$bgX = (int)(($width - $background->getImageWidth()) / 2);
			$bgY = (int)(($height - $background->getImageHeight()) / 2);
			$canvas->compositeImage($background, \Imagick::COMPOSITE_OVER, $bgX, $bgY);
			$background->clear();
		}

		$qrCode = new \Imagick($qrCodePath);
		$qrCode->setImageFormat('png');
		$qrSize = (int)(min($width, $height) * 0.35);
		$qrCode->resizeImage($qrSize, $qrSize, \Imagick::FILTER_LANCZOS, 1, true);
		$qrX = $width - $qrCode->getImageWidth() - max(4, (int)($qrSize * 0.05));
		$qrY = max(4, (int)($qrSize * 0.05));
		$canvas->compositeImage($qrCode, \Imagick::COMPOSITE_OVER, $qrX, $qrY);
		$qrCode->clear();

		$compositePath = $tempManager->getTemporaryFile('_background_qr.png');
		if (!$compositePath) {
			return $imagePath;
		}
		$canvas->writeImage($compositePath);
		$canvas->clear();

		return $compositePath;
	}

	#[\Override]
	public function readCertificate(): array {
		$result = $this->certificateEngineFactory
			->getEngine()
			->readCertificate(
				$this->getCertificate(),
				$this->getPassword()
			);

		if (!is_array($result)) {
			throw new \RuntimeException('Failed to read certificate data');
		}

		return $result;
	}

	private function buildMetadata(): SignatureMetadataDto {
		$params = $this->getSignatureParams();
		$name = !empty($params['SignerCommonName']) ? (string)$params['SignerCommonName'] : null;
		$email = !empty($params['SignerEmail']) ? (string)$params['SignerEmail'] : null;

		return new SignatureMetadataDto(
			actor: ($name !== null || $email !== null)
				? new SignatureActorDto(name: $name, contactInfo: $email)
				: null,
		);
	}

	private function resolvePageHeight(array $pageDimensions, int $pageIndex): float {
		$pageHeight = $pageDimensions[$pageIndex]['h'] ?? null;
		if (!is_numeric($pageHeight) || (float)$pageHeight <= 0.0) {
			throw new \RuntimeException(sprintf('Missing or invalid PageDimensions for page index %d.', $pageIndex));
		}
		return (float)$pageHeight;
	}

	private function buildTimestampOptions(): ?TimestampOptionsDto {
		$tsaUrl = $this->appConfig->getValueString(Application::APP_ID, 'tsa_url', '');
		if (empty($tsaUrl)) {
			return null;
		}

		$username = null;
		$password = null;
		$authType = $this->appConfig->getValueString(Application::APP_ID, 'tsa_auth_type', 'none');
		if ($authType === 'basic') {
			$username = $this->appConfig->getValueString(Application::APP_ID, 'tsa_username', '') ?: null;
			$password = $this->appConfig->getValueString(Application::APP_ID, 'tsa_password', '') ?: null;
		}

		return new TimestampOptionsDto(
			tsaUrl: $tsaUrl,
			username: $username,
			password: $password,
		);
	}

	private function resolveCertificationLevel(bool $noVisibleElements): ?CertificationLevel {
		if (!$this->docMdpConfigService->isEnabled()) {
			return null;
		}

		// DocMDP values mirror CertificationLevel: 1=NoChanges, 2=FormFilling, 3=FormFillAndAnnotations
		$level = $this->docMdpConfigService->getLevel()->value;
		// Only certify on invisible signatures or on the first visible element
		if ($noVisibleElements || !$this->hasExistingSignatures($this->getInputFile()->getContent())) {
			return CertificationLevel::fromInt($level);
		}

		return null;
	}

	private function hasExistingSignatures(string $pdfContent): bool {
		return (bool)preg_match('/\/ByteRange\s*\[|\/Type\s*\/Sig\b|\/DocMDP\b|\/Perms\b/', $pdfContent);
	}

	/**
	 * Builds the xObject (n2 layer) for all render modes using only PDF text operators.
	 *
	 * DESCRIPTION_ONLY      → description text, full width.
	 * GRAPHIC_AND_DESCRIPTION → description text, right half only
	 *                           (user image is in imagePath/n0, handled natively by signer-php).
	 * SIGNAME_AND_DESCRIPTION → signer name as large text on the left half
	 *                           + description text on the right half.
	 *                           No image generation: pure PDF text operators.
	 */
	private function buildXObject(int $width, int $height, string $renderMode): SignatureAppearanceXObjectDto {
		// GRAPHIC_ONLY: only the background/signature image is shown; no text in n2.
		if ($renderMode === SignerElementsService::RENDER_MODE_GRAPHIC_ONLY) {
			return new SignatureAppearanceXObjectDto(stream: '', resources: []);
		}

		$params = $this->getSignatureParams();
		$params['ServerSignatureDate'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
			->format(\DateTimeInterface::ATOM);

		$textData = $this->signatureTextService->parse(context: $params);
		$parsed = trim((string)($textData['parsed'] ?? ''));

		$descFontSize = (float)($textData['templateFontSize'] ?? $this->signatureTextService->getTemplateFontSize());
		$descLineHeight = $descFontSize * 1.0;
		$leftPadding = max(2.0, $descFontSize * 0.15);

		$isDescriptionOnly = $renderMode === SignerElementsService::RENDER_MODE_DESCRIPTION_ONLY;
		$textStartX = $isDescriptionOnly ? $leftPadding : ((float)$width / 2.0) + $leftPadding;
		$availableWidth = $isDescriptionOnly ? (float)$width : (float)$width / 2.0;

		$stream = '';

		// Left half: signer name as large text operators (SIGNAME_AND_DESCRIPTION only).
		// No image generation — the name is drawn directly with PDF text commands.
		// Left half: user's display/common name below the signature.
		if ($renderMode === SignerElementsService::RENDER_MODE_GRAPHIC_AND_DESCRIPTION) {
			$commonName = !empty($params['SignerCommonName'])
				? (string)$params['SignerCommonName']
				: ($this->readCertificate()['subject']['CN'] ?? '');

			if ($commonName !== '') {
				$nameFontSize = min(8.0, $descFontSize);
				$leftHalfW = (float)$width / 2.0;
				$nameWidth = strlen($commonName) * ($nameFontSize * 0.52);
				$nameX = max(
					$leftPadding,
					($leftHalfW - $nameWidth) / 2.0
				);

				$nameY = 18.0;

				$escaped = $this->escapePdfText($commonName);

				$stream .= "BT\n";
				$stream .= sprintf("/F1 %.2F Tf\n", $nameFontSize);
				$stream .= "0 0 0 rg\n";
				$stream .= sprintf("%.2F %.2F Td\n", $nameX, $nameY);
				$stream .= sprintf("(%s) Tj\n", $escaped);
				$stream .= "ET\n";
			}
		}

		// Right half (or full width): description text.
		$currentY = (float)$height - $descFontSize - 2.0;
		foreach (explode(PHP_EOL, $parsed) as $line) {
			$wrappedLines = $this->wrapTextForPdf($line, $availableWidth, $descFontSize);
			foreach ($wrappedLines as $wrappedLine) {
				if ($currentY < 0) {
					break 2;
				}
				$escaped = $this->escapePdfText($wrappedLine);
				$stream .= "BT\n";
				$stream .= sprintf("/F1 %.2F Tf\n", $descFontSize);
				$stream .= "0 0 0 rg\n";
				$stream .= sprintf("%.2F %.2F Td\n", $textStartX, $currentY);
				$stream .= sprintf("(%s) Tj\n", $escaped);
				$stream .= "ET\n";
				$currentY -= $descLineHeight;
			}
		}

		return new SignatureAppearanceXObjectDto(
			stream: $stream,
			resources: [
				'Font' => [
					'F1' => [
						'Type' => '/Font',
						'Subtype' => '/Type1',
						'BaseFont' => '/Helvetica',
					],
				],
			],
		);
	}

	/**
	 * @return string[]
	 */
	private function wrapTextForPdf(string $line, float $availableWidth, float $fontSize): array {
		$trimmed = trim($line);
		if ($trimmed === '') {
			return [''];
		}

		$estimatedCharWidth = max(1.0, $fontSize * 0.52);
		$maxChars = max(1, (int)floor($availableWidth / $estimatedCharWidth));
		if (strlen($trimmed) <= $maxChars) {
			return [$trimmed];
		}

		$result = [];
		$current = '';
		foreach (preg_split('/\s+/', $trimmed) ?: [] as $word) {
			if ($word === '') {
				continue;
			}

			$candidate = $current === '' ? $word : $current . ' ' . $word;
			if (strlen($candidate) <= $maxChars) {
				$current = $candidate;
				continue;
			}

			if ($current !== '') {
				$result[] = $current;
				$current = '';
			}

			while (strlen($word) > $maxChars) {
				$result[] = substr($word, 0, $maxChars);
				$word = substr($word, $maxChars);
			}

			$current = $word;
		}

		if ($current !== '') {
			$result[] = $current;
		}

		return $result;
	}

	private function escapePdfText(string $value): string {
		$value = str_replace('\\', '\\\\', $value);
		$value = str_replace('(', '\\(', $value);
		$value = str_replace(')', '\\)', $value);

		return $value;
	}
}
