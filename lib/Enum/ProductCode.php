<?php

declare(strict_types=1);

namespace OCA\Libresign\Enum;

enum ProductCode: string
{
	case SIGN_DOCUMENT = 'SIGN_DOCUMENT';
	case CERTIFICATE_ACCESS = 'CERTIFICATE_ACCESS';
}
