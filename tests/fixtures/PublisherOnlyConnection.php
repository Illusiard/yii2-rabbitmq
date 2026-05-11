<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\ConsumerInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
use RuntimeException;

class PublisherOnlyConnection implements ConnectionInterface
{
    private PublisherInterface $publisher;

    public function __construct(PublisherInterface $publisher)
    {
        $this->publisher = $publisher;
    }

    public function connect(): void
    {
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function close(): void
    {
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
