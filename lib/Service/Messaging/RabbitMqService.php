<?php

declare(strict_types=1);

namespace OCA\Libresign\Service\Messaging;

use OCA\Libresign\Config\RabbitMqConfig;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Throwable;

class RabbitMqService
{
	public function __construct(
		private RabbitMqConfig $config,
		private LoggerInterface $logger,
	) {}

	private function createConnection(): AMQPStreamConnection
	{
		return new AMQPStreamConnection(
			$this->config->getHost(),
			$this->config->getPort(),
			$this->config->getUser(),
			$this->config->getPassword(),
		);
	}

	/**
	 * Creates exchange + queue topology if it does not exist.
	 */
	private function setupTopology(AMQPChannel $channel): void
	{
		$channel->exchange_declare(
			$this->config->getExchangeName(),
			'direct',
			false,
			true,
			false
		);

		$channel->queue_declare(
			$this->config->getQueueName(),
			false,
			true,
			false,
			false
		);

		$channel->queue_bind(
			$this->config->getQueueName(),
			$this->config->getExchangeName(),
			$this->config->getRoutingKey()
		);
	}

	/**
	 * Queue a payment for verification.
	 */
	public function publishPaymentVerification(int $paymentId): void
	{
		$connection = $this->createConnection();

		try {
			$channel = $connection->channel();

			$this->setupTopology($channel);

			$payload = json_encode([
				'paymentId' => $paymentId,
			], JSON_THROW_ON_ERROR);

			$message = new AMQPMessage(
				$payload,
				[
					'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
				]
			);

			$channel->basic_publish(
				$message,
				$this->config->getExchangeName(),
				$this->config->getRoutingKey()
			);

			$this->logger->info(
				'[RabbitMQ] Payment verification queued',
				[
					'paymentId' => $paymentId,
				]
			);

			$channel->close();
		} finally {
			$connection->close();
		}
	}


	/**
	 * Consume payment verification messages.
	 *
	 * This worker blocks and continuously listens for
	 * payment verification events.
	 *
	 * The provided handler receives the paymentId.
	 */
	public function consumePayments(callable $handler): void
	{
		$connection = $this->createConnection();

		$channel = $connection->channel();

		$this->setupTopology($channel);

		// Fair dispatch
		$channel->basic_qos(
			null,
			1,
			null
		);

		$this->logger->info(
			'[RabbitMQ] Waiting for payment verification messages',
			[
				'queue' => $this->config->getQueueName(),
			]
		);

		$channel->basic_consume(
			$this->config->getQueueName(),
			'',
			false, // no_local
			false, // auto_ack
			false, // exclusive
			false, // nowait
			function (AMQPMessage $message) use ($handler): void {

				try {

					$payload = json_decode(
						$message->getBody(),
						true,
						512,
						JSON_THROW_ON_ERROR
					);

					$paymentId = (int) ($payload['paymentId'] ?? 0);

					$this->logger->info(
						'[RabbitMQ] Received payment verification message',
						[
							'paymentId' => $paymentId,
						]
					);

					$handler($paymentId);

					$message->ack();

					$this->logger->info(
						'[RabbitMQ] Message acknowledged',
						[
							'paymentId' => $paymentId,
						]
					);
				} catch (Throwable $e) {

					$this->logger->error(
						'[RabbitMQ] Failed processing message',
						[
							'error' => $e->getMessage(),
							'trace' => $e->getTraceAsString(),
						]
					);

				/*
				 * Requeue the message.
				 *
				 * Later we can evolve this into:
				 * - dead letter queues
				 * - delayed retries
				 * - max retry counts
				 */
					$message->nack(
						false,
						true
					);
				}
			}
		);

		try {

			while ($channel->is_consuming()) {

				$channel->wait();
			}
		} finally {

			$channel->close();

			$connection->close();
		}
	}
}
