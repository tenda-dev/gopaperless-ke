<?php

declare(strict_types=1);

namespace OCA\Libresign\Enum;

enum EntitlementType: string {
	case PAY_AS_YOU_GO = 'PAY_AS_YOU_GO';
	case SUBSCRIPTION = 'SUBSCRIPTION';
	case PROMOTIONAL = 'PROMOTIONAL';
	case ADMIN_GRANTED = 'ADMIN_GRANTED';
}
