<?php

namespace illusiard\rabbitmq\tests\integration\fixtures;

use illusiard\rabbitmq\consumer\ConsumerInterface;
use RuntimeException;

class ConsumeConsumer implements ConsumerInterface
{
    public function queue(): string
    {
        $queue = getenv('CONSUME_QUEUE');
        if (!$queue) {
            throw new RuntimeException('CONSUME_QUEUE is required.');
        }

        return $queue;
    }

    public function handler()
    {
        return ConsumeHandler::class;
    }

    public function options(): array
    {
        $prefetch = getenv('CONSUME_PREFETCH') ? (int)getenv('CONSUME_PREFETCH') : 1;

        return [
            'prefetch' => $prefetch,
        ];
    }
}
