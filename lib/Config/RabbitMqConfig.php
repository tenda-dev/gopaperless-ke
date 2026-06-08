<?php

declare(strict_types=1);

namespace OCA\Libresign\Config;

class RabbitMqConfig
{
    public function getHost(): string
    {
        return getenv('RABBITMQ_HOST') ?: 'rabbitmq';
    }

    public function getPort(): int
    {
        return (int)(getenv('RABBITMQ_PORT') ?: 5672);
    }

    public function getUser(): string
    {
        return getenv('RABBITMQ_USER') ?: 'admin';
    }

    public function getPassword(): string
    {
        return getenv('RABBITMQ_PASSWORD') ?: 'admin';
    }

    public function getQueueName(): string
    {
        return 'payment_verification';
    }

    public function getExchangeName(): string
    {
        return 'payments.exchange';
    }

    public function getRoutingKey(): string
    {
        return 'payment.verification';
    }
}
