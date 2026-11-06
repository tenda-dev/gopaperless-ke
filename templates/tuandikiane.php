<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
// A Tuandikiane draft's review page, the page where a signer known only by phone
// takes their signer slot, or the expired link. Laid out like the WhatsApp card
// (whatsapp_sign.php), whose styles it shares; tuandikiane.ts moves it through
// data-step and runs the MIMI passkey frame.
$mode = $_['mode'];
$screens = [
	'review' => [$l->t('Review your agreement'), $l->t('Check the details. When you tap Send, MIMI asks for your passkey, then everyone gets their signing link on WhatsApp.')],
	'bind' => [$l->t('Confirm it\'s you'), $l->t('Use your MIMI passkey to link this signature to your account. Then you can sign.')],
	'expired' => [$l->t('This link has expired.'), $l->t('Send your agreement on WhatsApp again.')],
];
[$title, $line] = $screens[$mode] ?? $screens['expired'];
$whatsappIcon = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91C2.13 13.66 2.59 15.36 3.45 16.86L2.05 22L7.3 20.62C8.75 21.41 10.38 21.83 12.04 21.83C17.5 21.83 21.95 17.38 21.95 11.92C21.95 9.27 20.92 6.78 19.05 4.91C17.18 3.03 14.69 2 12.04 2M12.05 3.67C14.25 3.67 16.31 4.53 17.87 6.09C19.42 7.65 20.28 9.72 20.28 11.92C20.28 16.46 16.58 20.15 12.04 20.15C10.56 20.15 9.11 19.76 7.85 19L7.55 18.83L4.43 19.65L5.26 16.61L5.06 16.29C4.24 15 3.8 13.47 3.8 11.91C3.81 7.37 7.5 3.67 12.05 3.67M8.53 7.33C8.37 7.33 8.1 7.39 7.87 7.64C7.65 7.89 7 8.5 7 9.71C7 10.93 7.89 12.1 8 12.27C8.14 12.44 9.76 14.94 12.25 16C12.84 16.27 13.3 16.42 13.66 16.53C14.25 16.72 14.79 16.69 15.22 16.63C15.7 16.56 16.68 16.03 16.89 15.45C17.1 14.87 17.1 14.38 17.04 14.27C16.97 14.17 16.81 14.11 16.56 14C16.31 13.86 15.09 13.26 14.87 13.18C14.64 13.1 14.5 13.06 14.31 13.3C14.15 13.55 13.67 14.11 13.53 14.27C13.38 14.44 13.24 14.46 13 14.34C12.74 14.21 11.94 13.95 11 13.11C10.26 12.45 9.77 11.64 9.62 11.39C9.5 11.15 9.61 11 9.73 10.89C9.84 10.78 10 10.6 10.1 10.45C10.23 10.31 10.27 10.2 10.35 10.04C10.43 9.87 10.39 9.73 10.33 9.61C10.27 9.5 9.77 8.26 9.56 7.77C9.36 7.29 9.16 7.35 9 7.34C8.86 7.34 8.7 7.33 8.53 7.33Z"/></svg>';
?>
<div class="guest-box login-box gp-whatsapp gp-tk"
	data-mode="<?php p($mode); ?>"
	data-step="<?php p($mode === 'expired' ? 'gone' : $mode); ?>"
	<?php if ($mode !== 'expired'): ?>
	data-options-url="<?php p($_['optionsUrl']); ?>"
	data-action-url="<?php p($_['actionUrl']); ?>"
	data-frame-url="<?php p($_['frameUrl']); ?>"
	<?php endif; ?>
	<?php if ($mode === 'review'): ?>
	data-pdf-url="<?php p($_['pdfUrl']); ?>"
	data-pages="<?php p(json_encode($_['pages'])); ?>"
	data-boxes="<?php p(json_encode($_['boxes'])); ?>"
	<?php endif; ?>>
	<div class="gp-whatsapp__screen">
		<span class="gp-whatsapp__badge" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
		</span>
		<h1 class="gp-whatsapp__title" id="gp-tk-title"><?php p($title); ?></h1>
		<?php if (($_['documentName'] ?? '') !== ''): ?>
			<div class="gp-whatsapp__file">
				<span class="gp-whatsapp__file-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
				</span>
				<span class="gp-whatsapp__file-text">
					<span class="gp-whatsapp__file-name"><?php p($_['documentName']); ?></span>
					<span class="gp-whatsapp__file-meta">PDF<?php if (($_['senderName'] ?? '') !== ''): ?> · <?php p($l->t('From %s', [$_['senderName']])); ?><?php endif; ?></span>
				</span>
			</div>
		<?php endif; ?>
		<p class="gp-whatsapp__status" role="status" aria-live="polite">
			<svg class="gp-whatsapp__spinner" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
			<svg class="gp-whatsapp__alert" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
			<span class="gp-whatsapp__wa"><?php print_unescaped($whatsappIcon); ?></span>
			<span id="gp-tk-line"><?php p($line); ?></span>
		</p>
		<?php if ($mode === 'review'): ?>
			<?php /* tuandikiane.ts draws each page here with its boxes. Without script, the PDF itself. */ ?>
			<div class="gp-tk__pages" id="gp-tk-pages">
				<a class="gp-tk__pdf" href="<?php p($_['pdfUrl']); ?>" target="_blank" rel="noopener"><?php p($l->t('Open the agreement')); ?></a>
			</div>
			<ol class="gp-tk__signers" aria-label="<?php p($l->t('Signers')); ?>">
				<?php foreach ($_['signers'] as $signer): ?>
					<li><span class="gp-tk__signer-name"><?php p($signer['name']); ?></span><?php if ($signer['sender']): ?> <span class="gp-tk__signer-role"><?php p($l->t('Sender')); ?></span><?php endif; ?></li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>
		<?php if ($mode !== 'expired'): ?>
			<button class="gp-whatsapp__button gp-tk__start" type="button" id="gp-tk-start"><?php p($mode === 'review' ? $l->t('Send for signing') : $l->t('Continue')); ?></button>
			<?php if ($mode === 'review'): ?>
				<a class="gp-whatsapp__button gp-tk__next" id="gp-tk-next" href="#"><?php p($l->t('Sign now')); ?></a>
			<?php endif; ?>
			<?php /* The hand-off to Chrome, as on the WhatsApp card, for a browser that turns out not to run a passkey. */ ?>
			<div class="gp-whatsapp__browser" hidden>
				<?php if ($_['chromeLink'] !== null): ?>
					<a class="gp-whatsapp__button" href="<?php p($_['chromeLink']); ?>"><?php p($l->t('Open in Chrome')); ?></a>
				<?php endif; ?>
				<button class="gp-whatsapp__button<?php if ($_['chromeLink'] !== null): ?> gp-whatsapp__button--quiet<?php endif; ?>" type="button" id="gp-tk-copy">
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
					<span><?php p($l->t('Copy link')); ?></span>
				</button>
			</div>
		<?php endif; ?>
	</div>
	<?php if ($mode !== 'expired'): ?>
		<div class="gp-whatsapp__frame" id="gp-tk-frame"></div>
	<?php endif; ?>
</div>
