<?php

declare(strict_types=1);

namespace OCA\Libresign\Enum;

enum SponsorshipType: string
{
	case SELF = 'self';

	case REQUESTER = 'requester';
}
