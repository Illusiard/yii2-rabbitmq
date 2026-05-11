<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\contracts\ConnectionInterface;

class RunnerLockRabbitMqService extends RabbitMqService
{
    private string $expectedLockFile;
    private string $failureMessage;

    public function __construct(string $expectedLockFile, string $failureMessage)
    {
        $this->expectedLockFile = $expectedLockFile;
        $this->failureMessage = $failureMessage;
        parent::__construct();
    }

    public function getConnection(): ConnectionInterface
    {
        return new RunnerLockConnection($this->expectedLockFile, $this->failureMessage);
    }
}
