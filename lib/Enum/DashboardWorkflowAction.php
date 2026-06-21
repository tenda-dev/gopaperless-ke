<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Enum;

enum DashboardWorkflowAction: string {
	case SIGN = 'SIGN';
	case VIEW = 'VIEW';
	case WAIT = 'WAIT';
	case COMPLETE_PAYMENT = 'COMPLETE_PAYMENT';
	case NONE = 'NONE';
}
