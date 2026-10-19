<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
$state = $_['state'];
// One screen per state, laid out like SecurySign's signing frame. On 'ready',
// whatsapp-sign.ts moves the screen through its steps and hands over to the frame.
$screens = [
	'ready' => [$l->t('Preparing to sign'), $l->t('Placing your signature on the page…')],
	'safari' => [$l->t('Open in Safari'), $l->t('WhatsApp\'s browser can\'t use passkeys. Open this page in Safari to sign.')],
	'signed' => [$l->t('Already signed'), $l->t('You\'ve already signed this document.')],
	'not_ready' => [$l->t('Not ready to sign'), $l->t('It isn\'t your turn to sign yet. You\'ll get a WhatsApp message when it is.')],
	'login' => [$l->t('Sign in to GoPaperless once'), $l->t('To sign from WhatsApp, sign in to GoPaperless once. You can sign this document there now.')],
	'no_credits' => [$l->t('No credits yet'), $l->t('Signing this document needs a credit. Buy one in GoPaperless and sign there.')],
	'gone' => [$l->t('This link doesn\'t work'), $l->t('The document may have been withdrawn. Ask the sender to send it again.')],
];
[$title, $line] = $screens[$state] ?? $screens['gone'];
?>
<div class="guest-box login-box gp-whatsapp<?php if ($state === 'ready'): ?> gp-whatsapp--flow<?php endif; ?>" data-step="<?php p($state === 'ready' ? 'preparing' : $state); ?>" data-sign-url="<?php p($_['signUrl']); ?>">
	<div class="gp-whatsapp__screen">
		<span class="gp-whatsapp__badge" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
		</span>
		<span class="gp-whatsapp__kicker">
			<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
			Secure signature confirmation<sup>™</sup>
		</span>
		<h1 class="gp-whatsapp__title" id="gp-whatsapp-title"><?php p($title); ?></h1>
		<?php if ($_['documentName'] !== ''): ?>
			<div class="gp-whatsapp__file">
				<span class="gp-whatsapp__file-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
				</span>
				<span class="gp-whatsapp__file-text">
					<span class="gp-whatsapp__file-name"><?php p($_['documentName']); ?></span>
					<span class="gp-whatsapp__file-meta">PDF · <?php p($l->t('From %s', [$_['senderName']])); ?></span>
				</span>
			</div>
		<?php endif; ?>
		<p class="gp-whatsapp__status" role="status" aria-live="polite">
			<svg class="gp-whatsapp__spinner" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
			<svg class="gp-whatsapp__alert" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
			<span id="gp-whatsapp-line"><?php p($line); ?></span>
		</p>
		<?php if ($state === 'ready'): ?>
			<button class="gp-whatsapp__button" type="button" id="gp-whatsapp-retry"><?php p($l->t('Try again')); ?></button>
		<?php elseif ($state === 'safari'): ?>
			<a class="gp-whatsapp__button" href="<?php p($_['safariUrl']); ?>"><?php p($l->t('Open in Safari')); ?></a>
			<button class="gp-whatsapp__link" type="button" id="gp-whatsapp-copy"><?php p($l->t('Copy link')); ?></button>
		<?php elseif ($state === 'login' || $state === 'no_credits'): ?>
			<a class="gp-whatsapp__button" href="<?php p($_['signLink']); ?>"><?php p($l->t('Open in GoPaperless')); ?></a>
		<?php endif; ?>
	</div>
	<?php if ($state === 'ready'): ?>
		<div class="gp-whatsapp__frame" id="gp-whatsapp-frame"></div>
	<?php endif; ?>
</div>
