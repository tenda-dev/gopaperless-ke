<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
// Tuandikiane's details form (TuandikianeFormController): the kinds of agreement,
// then one kind's fields. Laid out like the review page and the WhatsApp card,
// whose styles it shares. No script: the kinds are links, the form a plain POST.
$mode = $_['mode'];
$notices = [
	'expired' => [$l->t('This link has expired.'), $l->t('Send your agreement on WhatsApp again.')],
	'unavailable' => [$l->t('Couldn\'t open the form'), $l->t('Try again in a minute.')],
];
?>
<div class="guest-box login-box gp-whatsapp gp-tk gp-tk-form" data-step="<?php p(isset($notices[$mode]) ? 'gone' : $mode); ?>">
	<div class="gp-whatsapp__screen">
		<?php if (isset($notices[$mode])): ?>
			<h1 class="gp-whatsapp__title"><?php p($notices[$mode][0]); ?></h1>
			<p class="gp-whatsapp__status" role="status">
				<svg class="gp-whatsapp__alert" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
				<span><?php p($notices[$mode][1]); ?></span>
			</p>
		<?php elseif ($mode === 'pick'): ?>
			<h1 class="gp-whatsapp__title"><?php p($l->t('What kind of agreement?')); ?></h1>
			<p class="gp-whatsapp__status"><?php p($l->t('Pick one, then fill in the details.')); ?></p>
			<ul class="gp-tk__choices">
				<?php foreach ($_['choices'] as $choice): ?>
					<li>
						<a class="gp-tk__choice" href="<?php p($choice['url']); ?>">
							<span class="gp-tk__choice-title"><?php p($choice['title']); ?></span>
							<?php if ($choice['description'] !== ''): ?>
								<span class="gp-tk__choice-text"><?php p($choice['description']); ?></span>
							<?php endif; ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else: ?>
			<h1 class="gp-whatsapp__title"><?php p($_['template']['title']); ?></h1>
			<p class="gp-whatsapp__status"><?php p($_['template']['description'] !== '' ? $_['template']['description'] : $l->t('Fill in the details, then continue.')); ?></p>
			<form class="gp-tk__form" method="post" action="<?php p($_['action']); ?>">
				<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
				<input type="hidden" name="template" value="<?php p($_['template']['id']); ?>">
				<?php foreach ($_['formErrors'] as $error): ?>
					<p class="gp-tk__error gp-tk__error--form" role="alert"><?php p($error); ?></p>
				<?php endforeach; ?>
				<?php foreach ($_['fields'] as $field): ?>
					<?php
					$id = 'gp-tk-field-' . $field['name'];
					$input = $field['input'];
					?>
					<div class="gp-tk__field<?php if ($field['error'] !== ''): ?> gp-tk__field--error<?php endif; ?>">
						<label for="<?php p($id); ?>"><?php p($field['label']); ?><?php if (!$field['required']): ?> <span class="gp-tk__optional"><?php p($l->t('(optional)')); ?></span><?php endif; ?></label>
						<?php if ($input['type'] === 'textarea'): ?>
							<textarea id="<?php p($id); ?>" name="values[<?php p($field['name']); ?>]" rows="4"<?php if ($field['required']): ?> required<?php endif; ?><?php if ($field['error'] !== ''): ?> aria-invalid="true" aria-describedby="<?php p($id); ?>-error"<?php endif; ?>><?php p($field['value']); ?></textarea>
						<?php else: ?>
							<input id="<?php p($id); ?>" name="values[<?php p($field['name']); ?>]" type="<?php p($input['type']); ?>" value="<?php p($field['value']); ?>"<?php if (isset($input['inputmode'])): ?> inputmode="<?php p($input['inputmode']); ?>"<?php endif; ?><?php if (isset($input['autocomplete'])): ?> autocomplete="<?php p($input['autocomplete']); ?>"<?php endif; ?><?php if ($field['required']): ?> required<?php endif; ?><?php if ($field['error'] !== ''): ?> aria-invalid="true" aria-describedby="<?php p($id); ?>-error"<?php endif; ?>>
						<?php endif; ?>
						<?php if ($field['error'] !== ''): ?>
							<p class="gp-tk__error" id="<?php p($id); ?>-error"><?php p($field['error']); ?></p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
				<button class="gp-whatsapp__button" type="submit"><?php p($l->t('Continue')); ?></button>
			</form>
			<?php if ($_['chooseUrl'] !== ''): ?>
				<a class="gp-whatsapp__button gp-whatsapp__button--quiet" href="<?php p($_['chooseUrl']); ?>"><?php p($l->t('Choose another kind')); ?></a>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>
