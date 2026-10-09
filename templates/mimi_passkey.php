<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div class="guest-box login-box gp-passkey" data-return-to="<?php p($_['returnTo']); ?>" data-logout-url="<?php p($_['logoutUrl']); ?>">
	<iframe id="gp-passkey-frame"
		class="gp-passkey__frame"
		src="<?php p($_['frameUrl']); ?>"
		title="<?php p($l->t('MIMI passkey check')); ?>"
		allow="publickey-credentials-get"></iframe>
</div>
