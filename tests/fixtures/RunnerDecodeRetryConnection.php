<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\ConsumerInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
use RuntimeException;

class RunnerDecodeRetryConnection implements ConnectionInterface
{
    private FakeRetryService $service;

    public function __construct(FakeRetryService $service)
    {
        $this->service = $service;
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
        throw new RuntimeException('Publisher is provided by FakeRetryService::publish().');
    }

    public function getConsumer(): ConsumerInterface
    {
        return new RunnerDecodeRetryConsumer();
    }
}
