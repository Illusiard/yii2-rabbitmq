<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\ConsumerInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
use RuntimeException;

class ConsumeControllerConnection implements ConnectionInterface
{
    private ConsumeControllerRabbitMqService $service;

    public function __construct(ConsumeControllerRabbitMqService $service)
    {
        $this->service = $service;
    }

    public function connect(): void
    {
    }

    public function isConnected(): bool
    {
        return false;
    }

    public function close(): void
    {
    }

    public function getPublisher(): PublisherInterface
    {
        throw new RuntimeException('Not used in tests.');
    }

    public function getConsumer(): ConsumerInterface
    {
        return new ConsumeControllerConsumer($this->service);
    }
}
