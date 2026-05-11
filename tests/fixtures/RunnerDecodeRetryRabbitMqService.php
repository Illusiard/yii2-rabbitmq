<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\contracts\ConnectionInterface;

class RunnerDecodeRetryRabbitMqService extends FakeRetryService
{
    public function getConnection(): ConnectionInterface
    {
        return new RunnerDecodeRetryConnection();
    }
}
