<?php

declare(strict_types=1);

namespace OCA\Libresign\Enum;

enum PaymentPurpose: string
{
	case SIGN_REQUEST = 'sign_request';
	case CREDIT_PURCHASE = 'credit_purchase';
}
