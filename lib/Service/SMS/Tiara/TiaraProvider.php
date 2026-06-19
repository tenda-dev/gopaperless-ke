<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\SMS\Tiara;

use OCA\Libresign\Service\PhoneNumber\DTO\PhoneNumberDTO;
use OCA\Libresign\Service\SMS\Interfaces\ISMSProvider;
use Psr\Log\LoggerInterface;

final class TiaraProvider implements ISMSProvider
{
    private const SUPPORTED_REGIONS = [
        'KE',
        'TZ',
        'UG',
        'GH',
        'NG',
        'RW',
        'ZA',
        'ZM',
        'ZW',
        'AO',
        'BW',
        'CG',
        'CI',
        'CD',
        'MW',
        'MZ',
    ];

    public function __construct(
        private TiaraService $tiaraService,
        private LoggerInterface $logger,
    ) {
    }

    public function send(
        PhoneNumberDTO $phone,
        string $message,
    ): bool {

        if (!$phone->valid) {

            $this->logger->warning(
                '[TiaraProvider] Invalid phone number'
            );

            return false;
        }

        if (
            !in_array(
                $phone->region,
                self::SUPPORTED_REGIONS,
                true,
            )
        ) {

            $this->logger->warning(
                '[TiaraProvider] Unsupported region',
                [
                    'region' => $phone->region,
                    'phone' => $phone->e164,
                ]
            );

            return false;
        }

        return $this->tiaraService->send(
            $phone->e164Digits,
            $message,
        );
    }
}
