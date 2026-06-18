<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Libresign\Enum;

enum DashboardWorkflowStatus: string {
	case ACTION_REQUIRED = 'ACTION_REQUIRED';
	case WAITING_FOR_OTHERS = 'WAITING_FOR_OTHERS';
	case PAYMENT_REQUIRED = 'PAYMENT_REQUIRED';
	case COMPLETED = 'COMPLETED';
	case DRAFT = 'DRAFT';
}
