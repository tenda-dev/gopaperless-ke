<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * TECHNICAL SPIKE page — NOT production. Deliberately a plain server-rendered
 * form (no Vue, no inline script) that posts a multipart upload to
 * PublicUploadSpikeController::upload() and lets the browser navigate to the
 * JSON result. Used to verify, by hand, that an anonymous upload creates a
 * DRAFT owned by a server-created temporary user.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<div class="guest-box" style="max-width:640px;margin:2rem auto;padding:1.5rem;line-height:1.5">
	<p style="font-weight:700;color:var(--color-warning,#c38800);text-transform:uppercase;letter-spacing:.04em;margin:0 0 .5rem">
		<?php p($l->t('Technical spike — not for production')); ?>
	</p>
	<h2 style="margin:.25rem 0 1rem"><?php p($l->t('Public upload (temporary owner) spike')); ?></h2>
	<p style="margin:0 0 1rem;opacity:.85">
		<?php p($l->t('Pick a document and submit. The server creates a temporary user, saves the file as a DRAFT owned by that user through the normal upload pipeline, and returns the result as JSON. You are never logged in as the temporary user.')); ?>
	</p>

	<form method="post"
		action="<?php p($_['uploadUrl']); ?>"
		enctype="multipart/form-data">
		<input type="file" name="file" required
			style="display:block;margin:0 0 1rem" />
		<button type="submit" class="primary">
			<?php p($l->t('Upload as temporary user')); ?>
		</button>
	</form>

	<p style="margin:1.25rem 0 0;font-size:.85rem;opacity:.7">
		<?php p($l->t('Expected on success: ownerMatches = true, isDraft = true, and a fileUuid / nodeId. The temporary user owning the DRAFT is kept; a failed upload cleans its temporary user up.')); ?>
	</p>
</div>
