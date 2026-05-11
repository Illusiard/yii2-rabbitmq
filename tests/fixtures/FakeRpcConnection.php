<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\ConsumerInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
use RuntimeException;

class FakeRpcConnection implements ConnectionInterface
{
    private PublisherInterface $publisher;
    private FakeRpcChannelTimeout $channel;
    private bool $connected = true;

    public function __construct(PublisherInterface $publisher, FakeRpcChannelTimeout $channel)
    {
        $this->publisher = $publisher;
        $this->channel = $channel;
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

    public function getAmqpConnection(): object
    {
        return new FakeRpcAmqpConnection($this->channel, $this);
    }
}
