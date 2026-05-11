<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\contracts\ConnectionInterface;

class RunnerRecordingRabbitMqService extends RabbitMqService
{
    public bool $consumeCalled = false;

    public function getConnection(): ConnectionInterface
    {
        return new RunnerRecordingConnection($this);
    }
}
