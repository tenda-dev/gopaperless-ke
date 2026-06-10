<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Payment\Dispatchers;

use OCA\Libresign\Service\Payment\Interfaces\IVerificationDispatcher;
use OCA\Libresign\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Resolves which verification dispatcher should be used.
 *
 * RESPONSIBILITY:
 * - Read application configuration
 * - Select RabbitMQ or Nextcloud implementation
 * - Hide infrastructure decisions from PaymentService
 *
 * PRIORITY:
 * 1. Admin UI configuration
 * 2. Environment configuration fallback
 * 3. Safe default (Nextcloud)
 */
class VerificationDispatcherFactory implements IVerificationDispatcher
{
	private const CONFIG_KEY = 'payment_verification_dispatcher';

	private const RABBITMQ = 'rabbitmq';

	private const NEXTCLOUD = 'nextcloud';

	public function __construct(
		private IAppConfig $appConfig,
		private RabbitMqVerificationDispatcher $rabbitMqDispatcher,
		private NextcloudVerificationDispatcher $nextcloudDispatcher,
		private LoggerInterface $logger,
	) {}

	public function dispatchVerification(int $paymentId): void
	{
		$dispatcher = $this->resolveDispatcher();

		$this->logger->info(
			'[VerificationDispatcher] dispatching payment verification',
			[
				'paymentId' => $paymentId,
				'dispatcher' => $dispatcher::class,
			]
		);

		$dispatcher->dispatchVerification($paymentId);
	}

	private function resolveDispatcher(): IVerificationDispatcher
	{
		$driver = $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_KEY,
			self::NEXTCLOUD
		);

		return match ($driver) {
			self::RABBITMQ => $this->rabbitMqDispatcher,
			default => $this->nextcloudDispatcher,
		};
	}
}
