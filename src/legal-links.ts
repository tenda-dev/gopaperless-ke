/**
 * SPDX-FileCopyrightText: 2026 Tenda World
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Loaded on every page by LoginPageListener. Nextcloud's footer calls theming's
// imprint link "Legal notice". On GoPaperless it is the Terms & Conditions page
// (occ theming:config imprintUrl), and both links read as the landing's footer.
const labels: Record<string, string> = { 'Legal notice': 'Terms & Conditions', 'Privacy policy': 'Privacy Policy' }
for (const link of document.querySelectorAll<HTMLAnchorElement>('a.legal')) {
	link.textContent = labels[link.textContent?.trim() ?? ''] ?? link.textContent
}
