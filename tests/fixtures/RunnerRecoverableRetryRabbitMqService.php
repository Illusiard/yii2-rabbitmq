<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\contracts\ConnectionInterface;

class RunnerRecoverableRetryRabbitMqService extends FakeRetryService
{
    public function getConnection(): ConnectionInterface
    {
        return new RunnerMiddlewareConnection();
    }
}
