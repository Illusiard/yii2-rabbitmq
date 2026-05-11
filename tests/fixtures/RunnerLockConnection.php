<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\ConsumerInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
use RuntimeException;

class RunnerLockConnection implements ConnectionInterface
{
    private string $lockFile;
    private string $failureMessage;

    public function __construct(string $lockFile, string $failureMessage)
    {
        $this->lockFile = $lockFile;
        $this->failureMessage = $failureMessage;
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
        return new RunnerLockConsumer($this->lockFile, $this->failureMessage);
    }
}
