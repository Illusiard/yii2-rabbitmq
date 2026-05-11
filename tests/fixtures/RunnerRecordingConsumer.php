<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\contracts\ConsumerInterface;

class RunnerRecordingConsumer implements ConsumerInterface
{
    private RunnerRecordingRabbitMqService $service;

    public function __construct(RunnerRecordingRabbitMqService $service)
    {
        $this->service = $service;
    }

    public function consume(string $queue, callable $handler, int $prefetch = 1): void
    {
        $this->service->consumeCalled = true;
    }
}
