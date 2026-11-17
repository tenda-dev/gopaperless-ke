<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Exception;

/**
 * Rejects claim attempts when the submitted email does not match the
 * workflow's stored requester email.
 *
 * Keeps email verification failures distinct so the controller can return
 * a non-revealing response without exposing either email address.
 */
class EmailVerificationException extends LibresignException {
}
