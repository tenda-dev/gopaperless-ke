<?php

declare(strict_types=1);

namespace OCA\Libresign\Tests\Unit\Handler\SignEngine;

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Exception\SecurySignUnavailable;
use OCA\Libresign\Handler\CertificateEngine\CertificateEngineFactory;
use OCA\Libresign\Handler\SignEngine\PhpNativeHandler;
use OCA\Libresign\Handler\SignEngine\SecurySignHandler;
use OCA\Libresign\Service\DocMdp\ConfigService as DocMdpConfigService;
use OCA\Libresign\Service\SignatureBackgroundService;
use OCA\Libresign\Service\SignatureTextService;
use OCP\Files\File;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use SignerPHP\Infrastructure\Native\Contract\Pkcs7SignerInterface;
use SignerPHP\Infrastructure\PdfCore\Buffer;
use SignerPHP\Infrastructure\PdfCore\Signature;

/**
 * A local P-256 key plays SecurySign's HSM. OpenSSL, not this code, decides
 * whether the finished PDF signature verifies.
 */
final class SecurySignHandlerTest extends TestCase {
	private const PDF = __DIR__ . '/../../../fixtures/pdfs/small_valid.pdf';

	public function testSecurySignSignatureCompletesThePreparedRevision(): void {
		[$key, $pem] = self::certificate();
		[$prepared, $attributes] = $this->prepare($pem);
		openssl_sign($attributes, $signature, $key, OPENSSL_ALGO_SHA256);

		// finalize() relies on this to notice a signature that landed meanwhile.
		$this->assertStringStartsWith((string)file_get_contents(self::PDF), $prepared);

		$signed = SecurySignHandler::embed($prepared, $attributes, $signature, $pem);
		$this->assertSame(strlen($prepared), strlen($signed));
		$this->assertTrue(self::verifies($signed));

		// NetHSM may answer with raw r||s instead of DER.
		$this->assertTrue(self::verifies(SecurySignHandler::embed($prepared, $attributes, self::raw($signature), $pem)));
	}

	public function testRefusesASignatureFromAnotherKey(): void {
		[, $pem] = self::certificate();
		[$otherKey] = self::certificate();
		[$prepared, $attributes] = $this->prepare($pem);
		openssl_sign($attributes, $signature, $otherKey, OPENSSL_ALGO_SHA256);

		$this->expectException(LibresignException::class);
		$this->expectExceptionCode(403);
		$this->expectExceptionMessage('Wrong passkey');
		SecurySignHandler::embed($prepared, $attributes, $signature, $pem);
	}

	public function testOnlyAnOutageFallsBackToTheLocalEngine(): void {
		$outcome = static function (\Exception $e): string {
			try {
				(new \ReflectionMethod(SecurySignHandler::class, 'userFacing'))->invoke(null, static fn () => throw $e);
			} catch (LibresignException $thrown) {
				return $thrown instanceof SecurySignUnavailable ? 'local engine' : 'shown to user';
			}
			return 'nothing thrown';
		};
		$this->assertSame('local engine', $outcome(new \RuntimeException('SecurySign answered HTTP 502', 503)));
		// A refused connection surfaces from Guzzle with code 0.
		$this->assertSame('local engine', $outcome(new \RuntimeException('cURL error 7: Failed to connect')));
		$this->assertSame('shown to user', $outcome(new \RuntimeException('Sign in again to connect to SecurySign.', 401)));
		$this->assertSame('shown to user', $outcome(new \RuntimeException('You need an active SecurySign certificate to sign.', 409)));
	}

	/** @return array{\OpenSSLAsymmetricKey, string} */
	private static function certificate(): array {
		$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
		$csr = openssl_csr_new(['commonName' => 'SecurySign Test Signer'], $key, ['digest_alg' => 'sha256']);
		openssl_x509_export(openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256']), $pem);
		return [$key, $pem];
	}

	/** Runs the real PhpNativeHandler with the same capture SecurySignHandler::prepare() uses. */
	private function prepare(string $pem): array {
		$capture = new class($pem) implements Pkcs7SignerInterface {
			public string $attributes = '';

			public function __construct(
				private string $pem,
			) {
			}

			public function sign(Signature $signatureHandler, Buffer $signableDocument): string {
				$this->attributes = SecurySignHandler::signedAttributes(hash('sha256', $signableDocument->raw(), true), $this->pem, time());
				return str_repeat('0', Signature::SIGNATURE_MAX_LENGTH);
			}
		};
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn(file_get_contents(self::PDF));
		$prepared = (new PhpNativeHandler(
			$this->createMock(IAppConfig::class),
			$this->createMock(DocMdpConfigService::class),
			$this->createMock(SignatureTextService::class),
			$this->createMock(SignatureBackgroundService::class),
			$this->createMock(CertificateEngineFactory::class),
		))
			->setExternalSigner($capture)
			->setCertificate($pem)
			->setInputFile($file)
			->setSignatureParams(['SignerCommonName' => 'SecurySign Test Signer'])
			->getSignedContent();
		return [$prepared, $capture->attributes];
	}

	private static function verifies(string $pdf): bool {
		preg_match_all('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $ranges, PREG_SET_ORDER);
		[, $from1, $length1, $from2, $length2] = array_map('intval', end($ranges));
		$hex = rtrim(substr($pdf, $from1 + $length1 + 1, $from2 - $from1 - $length1 - 2), '0');
		$content = tempnam(sys_get_temp_dir(), 'ss-content');
		$cms = tempnam(sys_get_temp_dir(), 'ss-cms');
		file_put_contents($content, substr($pdf, $from1, $length1) . substr($pdf, $from2, $length2));
		// DER may legitimately end in a zero nibble that rtrim took; put it back.
		file_put_contents($cms, hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex));
		try {
			return openssl_cms_verify($content, OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY | OPENSSL_CMS_NOVERIFY, null, [], null, null, null, $cms, OPENSSL_ENCODING_DER);
		} finally {
			unlink($content);
			unlink($cms);
		}
	}

	private static function raw(string $der): string {
		$offset = 2;
		$raw = '';
		for ($i = 0; $i < 2; $i++) {
			$length = ord($der[$offset + 1]);
			$raw .= str_pad(ltrim(substr($der, $offset + 2, $length), "\0"), 32, "\0", STR_PAD_LEFT);
			$offset += 2 + $length;
		}
		return $raw;
	}
}
