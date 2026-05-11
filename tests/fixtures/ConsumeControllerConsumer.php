<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\contracts\ConsumerInterface;

class ConsumeControllerConsumer implements ConsumerInterface
{
    private ConsumeControllerRabbitMqService $service;

    public function __construct(ConsumeControllerRabbitMqService $service)
    {
        $this->service = $service;
    }

    public function consume(string $queue, callable $handler, int $prefetch = 1): void
    {
        $this->service->lastQueue = $queue;
        $this->service->lastPrefetch = $prefetch;
        $handler('body', [
            'body' => 'body',
            'headers' => [],
            'properties' => [],
            'delivery_tag' => 1,
            'routing_key' => 'routing',
            'exchange' => 'exchange',
            'redelivered' => false,
        ]);
        $this->service->handlerCalled = true;
        $this->service->handlerQueue = $queue;
    }
}
