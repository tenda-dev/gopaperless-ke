<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div class="guest-box login-box gp-passkey" data-return-to="<?php p($_['returnTo']); ?>">
	<h2 class="login-form__headline"><?php p($l->t('Confirm it is you')); ?></h2>
	<p class="gp-passkey__text"><?php p($l->t('Use your MIMI passkey to open GoPaperless.')); ?></p>
	<button type="button" class="gp-passkey__button" id="gp-passkey-start">
		<?php p($l->t('Use my MIMI passkey')); ?>
	</button>
	<p class="gp-passkey__error" id="gp-passkey-error" role="alert" hidden></p>
	<a class="gp-passkey__signout" href="<?php p($_['logoutUrl']); ?>"><?php p($l->t('Sign out')); ?></a>
</div>
