<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Shared branded loading shell rendered server-side inside #content on
 * anonymous GoPaperless routes. It provides a stable loading state while the
 * Vue application loads and replaces it when mounted.
 *
 * Styling is injected into the document head as critical CSS, allowing the
 * shell to render on first paint without depending on Nextcloud styles or
 * components. The shell uses shared copy across all supported routes.
 *
 * The reload link always remains available as a same-page navigation fallback
 * if the application fails to load. It does not detect loading failures.
 */
?>
<div class="gp-shell" role="status" aria-busy="true">
	<div class="gp-shell__brand">
		<img class="gp-shell__logo"
			src="<?php p(image_path('libresign', 'gopaperless-logo-ink.png')); ?>"
			width="1161" height="181" alt="GoPaperless">
		<p class="gp-shell__tagline">Sign · Seal · Deliver</p>
		<div class="gp-shell__by">
			<span>with</span>
			<img class="gp-shell__by-logo"
				src="<?php p(image_path('libresign', 'tenda-logo-green-updated.png')); ?>"
				width="2322" height="468" alt="Tendaworld">
		</div>
	</div>
	<div class="gp-shell__loader">
		<span class="gp-shell__spinner" aria-hidden="true"></span>
		<div class="gp-shell__msgwrap" aria-hidden="true">
			<p class="gp-shell__message">Preparing your secure workspace…</p>
			<p class="gp-shell__message">Getting things ready…</p>
			<p class="gp-shell__message">Almost ready…</p>
		</div>
		<span class="gp-shell__sr">Loading GoPaperless…</span>
	</div>
	<a class="gp-shell__reload" href="<?php p($_['reloadUrl'] ?? ''); ?>">Reload</a>
</div>
