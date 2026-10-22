/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'

/**
 * Client-side public-upload file rules, shared by every anonymous entry point
 * (the /p/workflow drop zone and the /p/upload landing's direct upload) so the
 * two never diverge. The server still enforces its own instance limit; this is
 * the pre-flight that keeps an oversized/invalid file from ever creating a
 * workflow or hitting the upload endpoint.
 */
export const MAX_FILE_SIZE_MB = 25
export const MAX_FILE_SIZE_BYTES = MAX_FILE_SIZE_MB * 1024 * 1024

/**
 * Validate a candidate PDF upload. Returns a human-readable error message when
 * the file is not acceptable, or null when it passes.
 */
export function validatePublicUploadFile(file: File): string | null {
	if (file.type !== 'application/pdf') {
		return t('libresign', 'Please choose a PDF file.')
	}
	if (file.size > MAX_FILE_SIZE_BYTES) {
		return t('libresign', 'That file is larger than {n}MB. Choose a smaller PDF.', { n: MAX_FILE_SIZE_MB })
	}
	return null
}
