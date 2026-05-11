<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\contracts\ConnectionInterface;

class RunnerMiddlewareRabbitMqService extends RabbitMqService
{
    public function getConnection(): ConnectionInterface
    {
        return new RunnerMiddlewareConnection();
    }
}
