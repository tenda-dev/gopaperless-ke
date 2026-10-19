<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
$state = $_['state'];
?>
<div class="guest-box login-box gp-whatsapp" data-state="<?php p($state); ?>" data-sign-url="<?php p($_['signUrl']); ?>">
	<?php if ($state === 'ready'): ?>
		<p class="gp-whatsapp__from"><?php p($l->t('From %s', [$_['senderName']])); ?></p>
		<h2 class="gp-whatsapp__title"><?php p($_['documentName']); ?></h2>
		<div class="gp-whatsapp__frame" id="gp-whatsapp-frame"></div>
		<p class="gp-whatsapp__status" id="gp-whatsapp-status" role="status" aria-live="polite"><?php p($l->t('Preparing the document…')); ?></p>
		<div class="gp-whatsapp__done" id="gp-whatsapp-done" hidden>
			<h2 class="gp-whatsapp__title"><?php p($l->t('Signed')); ?></h2>
			<p><?php p($l->t('You signed "%s". Your copy is on its way to you on WhatsApp.', [$_['documentName']])); ?></p>
		</div>
	<?php elseif ($state === 'safari'): ?>
		<h2 class="gp-whatsapp__title"><?php p($l->t('Open in Safari')); ?></h2>
		<p><?php p($l->t('WhatsApp\'s browser can\'t use passkeys. Open this page in Safari to sign.')); ?></p>
		<a class="gp-whatsapp__button" href="<?php p($_['safariUrl']); ?>"><?php p($l->t('Open in Safari')); ?></a>
		<button class="gp-whatsapp__link" type="button" id="gp-whatsapp-copy"><?php p($l->t('Copy link')); ?></button>
	<?php elseif ($state === 'signed'): ?>
		<h2 class="gp-whatsapp__title"><?php p($l->t('Signed')); ?></h2>
		<p><?php p($l->t('You already signed "%s".', [$_['documentName']])); ?></p>
	<?php elseif ($state === 'not_ready'): ?>
		<h2 class="gp-whatsapp__title"><?php p($l->t('Not ready to sign')); ?></h2>
		<p><?php p($l->t('"%s" isn\'t ready for your signature yet. You\'ll get a message when it is.', [$_['documentName']])); ?></p>
	<?php elseif ($state === 'login'): ?>
		<h2 class="gp-whatsapp__title"><?php p($l->t('Sign in to GoPaperless once')); ?></h2>
		<p><?php p($l->t('To sign from WhatsApp, sign in to GoPaperless once. You can sign "%s" there now.', [$_['documentName']])); ?></p>
		<a class="gp-whatsapp__button" href="<?php p($_['signLink']); ?>"><?php p($l->t('Open in GoPaperless')); ?></a>
	<?php elseif ($state === 'no_credits'): ?>
		<h2 class="gp-whatsapp__title"><?php p($l->t('No credits yet')); ?></h2>
		<p><?php p($l->t('Signing "%s" needs a credit. Buy one in GoPaperless and sign there.', [$_['documentName']])); ?></p>
		<a class="gp-whatsapp__button" href="<?php p($_['signLink']); ?>"><?php p($l->t('Open in GoPaperless')); ?></a>
	<?php else: ?>
		<h2 class="gp-whatsapp__title"><?php p($l->t('This link doesn\'t work')); ?></h2>
		<p><?php p($l->t('The document may have been withdrawn. Ask the sender to send it again.')); ?></p>
	<?php endif; ?>
</div>
