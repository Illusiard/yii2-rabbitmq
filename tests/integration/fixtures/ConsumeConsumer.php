<?php

namespace app\tests\integration\fixtures;

use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;
use RuntimeException;

class ConsumeConsumer implements ConsumerInterface
{
    public function getQueue(): string
    {
        $queue = getenv('CONSUME_QUEUE');
        if (!$queue) {
            throw new RuntimeException('CONSUME_QUEUE is required.');
        }

        return $queue;
    }

    public function getHandler()
    {
        return ConsumeHandler::class;
    }

    public function getOptions(): array
    {
        $prefetch = getenv('CONSUME_PREFETCH') ? (int)getenv('CONSUME_PREFETCH') : 1;

        return [
            'prefetch' => $prefetch,
        ];
    }

    public function getMiddlewares(): array
    {
        return [];
    }
}
