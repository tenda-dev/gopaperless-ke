/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and LibreCode contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { once } from '../../utils/asyncRetry'

/**
 * Lazy loaders for public-workflow-specific components.
 *
 * Loads the signature field editor and its @libresign/pdf-elements dependency.
 * Shared by the editor's async boundary and the shell's "Continue to fields"
 * preload, ensuring both paths reuse the same import.
 */
export const loadFieldEditor = once(() => import('../Request/VisibleElementsNext/PdfEditorNext.vue'))
