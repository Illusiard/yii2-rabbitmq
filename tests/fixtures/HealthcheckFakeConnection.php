<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\ConsumerInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
use RuntimeException;

class HealthcheckFakeConnection implements ConnectionInterface
{
    private PublisherInterface $publisher;
    private bool $connected = false;

    public function __construct(PublisherInterface $publisher)
    {
        $this->publisher = $publisher;
    }

    public function connect(): void
    {
        $this->connected = true;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function getPublisher(): PublisherInterface
    {
        return $this->publisher;
    }

    public function getConsumer(): ConsumerInterface
    {
        throw new RuntimeException('Not implemented.');
    }
}
