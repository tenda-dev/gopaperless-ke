<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\SMS\Tiara;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\SMS\Tiara\DTO\TiaraConfigDTO;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

final class TiaraConfigurationService
{
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {}

	/**
	 * Retrieves and validates Tiara configuration.
	 *
	 * Responsibilities:
	 * - Load Tiara settings from Nextcloud AppConfig
	 * - Validate required configuration values
	 * - Map configuration into TiaraConfigDTO
	 *
	 * Returns null when mandatory configuration is missing.
	 */
	public function get(): ?TiaraConfigDTO
	{
		$apiKey = trim(
			$this->appConfig->getValueString(
				Application::APP_ID,
				'tiara_api_key',
				''
			)
		);

		$senderId = trim(
			$this->appConfig->getValueString(
				Application::APP_ID,
				'tiara_sender_id',
				''
			)
		);

		if (
			$apiKey === '' ||
			$senderId === ''
		) {

			$this->logger->error(
				'[Tiara] Missing configuration',
				[
					'missingApiKey' => $apiKey === '',
					'missingSenderId' => $senderId === '',
					'app' => Application::APP_ID,
				]
			);

			return null;
		}

		return new TiaraConfigDTO(
			apiKey: $apiKey,
			senderId: $senderId,
		);
	}
}
