<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\contracts\ConnectionInterface;

class ConsumeControllerRabbitMqService extends RabbitMqService
{
    public ?string $lastQueue = null;
    public ?int $lastPrefetch = null;
    public bool $handlerCalled = false;
    public ?string $handlerQueue = null;

    public function getConnection(): ConnectionInterface
    {
        return new ConsumeControllerConnection($this);
    }
}
