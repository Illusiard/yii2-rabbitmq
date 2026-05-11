<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\contracts\ConsumerInterface;
use RuntimeException;

class RunnerLockConsumer implements ConsumerInterface
{
    private string $lockFile;
    private string $failureMessage;

    public function __construct(string $lockFile, string $failureMessage)
    {
        $this->lockFile = $lockFile;
        $this->failureMessage = $failureMessage;
    }

    public function consume(string $queue, callable $handler, int $prefetch = 1): void
    {
        if (!is_file($this->lockFile)) {
            throw new RuntimeException($this->failureMessage);
        }

        throw new RuntimeException('Forced failure.');
    }
}
