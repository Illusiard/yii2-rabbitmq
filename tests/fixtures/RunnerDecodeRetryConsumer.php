<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\contracts\ConsumerInterface;

class RunnerDecodeRetryConsumer implements ConsumerInterface
{
    public function consume(string $queue, callable $handler, int $prefetch = 1): void
    {
        $handler('{invalid json}', [
            'headers' => [],
            'properties' => [],
            'delivery_tag' => 1,
            'routing_key' => 'routing',
            'exchange' => 'exchange',
            'redelivered' => false,
        ]);
    }
}
