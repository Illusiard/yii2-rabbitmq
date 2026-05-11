<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\ConsumerInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
use RuntimeException;

class HealthcheckThrowingConnection implements ConnectionInterface
{
    public function connect(): void
    {
        throw new RuntimeException('Connect failed');
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
        throw new RuntimeException('Not implemented.');
    }

    public function getConsumer(): ConsumerInterface
    {
        throw new RuntimeException('Not implemented.');
    }
}
