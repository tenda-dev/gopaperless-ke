<?php

/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Rendered inside Nextcloud's guest layout, so it inherits the instance logo,
 * theme colour and footer without shipping any CSS of its own. Every path out of
 * the gate lands here, and every one of them offers something to click — a dead
 * end with no navigation is what this template exists to replace.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<div class="guest-box">
	<h2><?php p($_['title']); ?></h2>
	<p><?php p($_['message']); ?></p>
	<p>
		<a class="button primary" href="<?php p($_['actionUrl']); ?>"><?php p($_['actionLabel']); ?></a>
	</p>
	<p>
		<a href="<?php p($_['secondaryUrl']); ?>"><?php p($_['secondaryLabel']); ?></a>
	</p>
</div>
