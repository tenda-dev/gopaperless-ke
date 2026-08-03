<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\SignatureProfile\ValueObject;

/**
 * Immutable description of which visual elements are rendered on a signed
 * document. Resolved once per signing request and consumed by the rendering
 * pipeline; handlers receive this value object and never perform group lookups.
 */
class SignatureProfile {
	private SignatureStamp $stamp;

	public function __construct(
		private bool $footer = true,
		private bool $qr = true,
		?SignatureStamp $stamp = null,
		private bool $auditInfo = true,
	) {
		$this->stamp = $stamp ?? SignatureStamp::default();
	}

	/**
	 * The default profile renders every element (default-on) with the stamp
	 * inheriting the global configuration.
	 */
	public static function default(): self {
		return new self(true, true, SignatureStamp::default(), true);
	}

	/**
	 * Build from a stored/config array. Missing boolean flags default to `true`,
	 * so partial or malformed entries never disable an element unintentionally.
	 * The `stamp` key may be a legacy boolean or the object shape.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self {
		return new self(
			(bool)($data['footer'] ?? true),
			(bool)($data['qr'] ?? true),
			SignatureStamp::fromArray($data['stamp'] ?? null),
			(bool)($data['auditInfo'] ?? true),
		);
	}

	/**
	 * @return array{footer: bool, qr: bool, stamp: array<string, bool|string|float>, auditInfo: bool}
	 */
	public function toArray(): array {
		return [
			'footer' => $this->footer,
			'qr' => $this->qr,
			'stamp' => $this->stamp->toArray(),
			'auditInfo' => $this->auditInfo,
		];
	}

	public function shouldRenderFooter(): bool {
		return $this->footer;
	}

	public function shouldRenderQrCode(): bool {
		return $this->qr;
	}

	public function shouldRenderStamp(): bool {
		return $this->stamp->isEnabled();
	}

	public function getStamp(): SignatureStamp {
		return $this->stamp;
	}

	public function shouldRenderAuditInfo(): bool {
		return $this->auditInfo;
	}
}
