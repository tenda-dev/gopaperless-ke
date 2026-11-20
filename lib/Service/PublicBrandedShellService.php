<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCP\IRequest;
use OCP\Util;

/**
 * Prepares the shared branded loading shell for anonymous GoPaperless routes.
 *
 * Provides the critical styles and template parameters needed to render the
 * server-side shell before the public Vue application mounts.
 */
class PublicBrandedShellService {
	public function __construct(
		protected IRequest $request,
	) {
	}

	/**
	 * Injects the shell's critical styles and returns its template parameters.
	 *
	 * The styles are added to the document head so the shell can render before
	 * the Vue application mounts. The returned parameters enable the shell
	 * in the main template and provide the current URL for the reload action.
	 */
	public function params(): array {
		$criticalCss = <<<'CSS'
	#body-public {
		background: #fff !important;
		--color-main-background: #fff;
	}

	#body-public #header,
	#body-public footer.guest-box {
		display: none !important;
	}

	#body-public #content {
		background: #fff;
	}

	.gp-shell {
		position: fixed;
		inset: 0;
		height: 100dvh;
		width: 100%;
		box-sizing: border-box;
		padding: 24px;
		background: #fff;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 30px;
		z-index: 1;
		font-family: 'Space Grotesk', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
		color: #0e1116;
	}

	.gp-shell__brand {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 14px;
	}

	.gp-shell__logo {
		height: 34px;
		width: auto;
		display: block;
	}

	.gp-shell__tagline {
		margin: 0;
		font-size: 11px;
		font-weight: 600;
		letter-spacing: .16em;
		text-transform: uppercase;
		color: #03b95e;
	}

	.gp-shell__by {
		display: flex;
		align-items: center;
		gap: 6px;
		margin-top: 8px;
		font-size: 12px;
		line-height: 16px;
		color: #5b6472;
	}

	.gp-shell__by-logo {
		display: block;
		width: auto;
		height: 19px;
		margin-top: -3px;
	}

	.gp-shell__loader {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 12px;
	}

	.gp-shell__spinner {
		width: 26px;
		height: 26px;
		border-radius: 50%;
		border: 2.5px solid #e6e8ec;
		border-top-color: #0e1116;
		animation: gp-shell-spin .7s linear infinite;
	}

	@keyframes gp-shell-spin {
		to {
			transform: rotate(360deg);
		}
	}

	.gp-shell__msgwrap {
		position: relative;
		width: min(260px, 86vw);
		height: 18px;
	}

	.gp-shell__message {
		position: absolute;
		inset: 0;
		margin: 0;
		font-size: 13px;
		line-height: 18px;
		color: #5b6472;
		text-align: center;
		opacity: 0;
		animation: gp-shell-msg 9s ease-in-out infinite;
	}

	.gp-shell__message:nth-child(1) {
		animation-delay: 0s;
	}

	.gp-shell__message:nth-child(2) {
		animation-delay: -3s;
	}

	.gp-shell__message:nth-child(3) {
		animation-delay: -6s;
	}

	@keyframes gp-shell-msg {
		0% {
			opacity: 0;
		}

		4% {
			opacity: 1;
		}

		29% {
			opacity: 1;
		}

		33.3% {
			opacity: 0;
		}

		100% {
			opacity: 0;
		}
	}

	.gp-shell__sr {
		position: absolute;
		width: 1px;
		height: 1px;
		margin: -1px;
		padding: 0;
		overflow: hidden;
		clip: rect(0 0 0 0);
		white-space: nowrap;
		border: 0;
	}

	.gp-shell__reload {
		font-size: 12px;
		color: #5b6472;
		text-decoration: underline;
		text-underline-offset: 2px;
	}

	.gp-shell__reload:hover {
		color: #0e1116;
	}

	@media (max-width: 860px) {
		.gp-shell__logo {
			height: 28px;
		}
	}

	@media (prefers-reduced-motion: reduce) {
		.gp-shell__spinner {
			animation: none;
			border-top-color: #e6e8ec;
		}

		.gp-shell__message {
			animation: none;
			opacity: 0;
		}

		.gp-shell__message:nth-child(1) {
			opacity: 1;
		}
	}
	CSS;

		Util::addHeader('style', [], $criticalCss);

		return [
			'publicShell' => true,
			'reloadUrl' => $this->request->getRequestUri(),
		];
	}
}
