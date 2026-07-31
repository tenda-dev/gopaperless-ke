<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service\SignatureProfile\ValueObject;

/**
 * Per-customer signature stamp configuration.
 *
 * `enabled` toggles whether the visible stamp is rendered at all. The remaining
 * fields are overrides for the global stamp settings: a `null` value means
 * "inherit the global setting", so a default stamp keeps the current behaviour
 * byte-for-byte. Only fields a customer explicitly pins hold a non-null value.
 */
class SignatureStamp {
	public function __construct(
		private bool $enabled = true,
		private ?string $renderMode = null,
		private ?string $textTemplate = null,
		private ?float $signatureFontSize = null,
		private ?float $templateFontSize = null,
		private ?float $width = null,
		private ?float $height = null,
	) {
	}

	/**
	 * Default stamp: enabled, every field inheriting the global configuration.
	 */
	public static function default(): self {
		return new self(true);
	}

	/**
	 * Build from stored data. Accepts a legacy boolean (old `stamp: true|false`
	 * shape) or the object shape. Missing `enabled` defaults to `true`; missing
	 * override fields stay `null` (inherit global).
	 *
	 * @param array<string, mixed>|bool|null $data
	 */
	public static function fromArray(array|bool|null $data): self {
		if (is_bool($data)) {
			return new self($data);
		}
		if (!is_array($data)) {
			return self::default();
		}
		return new self(
			(bool)($data['enabled'] ?? true),
			self::nullableString($data['renderMode'] ?? null),
			self::nullableString($data['textTemplate'] ?? null),
			self::nullableFloat($data['signatureFontSize'] ?? null),
			self::nullableFloat($data['templateFontSize'] ?? null),
			self::nullableFloat($data['width'] ?? null),
			self::nullableFloat($data['height'] ?? null),
		);
	}

	/**
	 * Round-trippable representation. `enabled` is always present; override
	 * fields are emitted only when pinned, keeping stored config lean.
	 *
	 * @return array<string, bool|string|float>
	 */
	public function toArray(): array {
		$data = ['enabled' => $this->enabled];
		if ($this->renderMode !== null) {
			$data['renderMode'] = $this->renderMode;
		}
		if ($this->textTemplate !== null) {
			$data['textTemplate'] = $this->textTemplate;
		}
		if ($this->signatureFontSize !== null) {
			$data['signatureFontSize'] = $this->signatureFontSize;
		}
		if ($this->templateFontSize !== null) {
			$data['templateFontSize'] = $this->templateFontSize;
		}
		if ($this->width !== null) {
			$data['width'] = $this->width;
		}
		if ($this->height !== null) {
			$data['height'] = $this->height;
		}
		return $data;
	}

	public function isEnabled(): bool {
		return $this->enabled;
	}

	public function getRenderMode(): ?string {
		return $this->renderMode;
	}

	public function getTextTemplate(): ?string {
		return $this->textTemplate;
	}

	public function getSignatureFontSize(): ?float {
		return $this->signatureFontSize;
	}

	public function getTemplateFontSize(): ?float {
		return $this->templateFontSize;
	}

	public function getWidth(): ?float {
		return $this->width;
	}

	public function getHeight(): ?float {
		return $this->height;
	}

	private static function nullableString(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}
		return (string)$value;
	}

	private static function nullableFloat(mixed $value): ?float {
		if ($value === null || $value === '') {
			return null;
		}
		return (float)$value;
	}
}
