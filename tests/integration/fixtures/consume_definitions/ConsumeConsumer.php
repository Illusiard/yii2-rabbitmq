<?php

namespace illusiard\rabbitmq\tests\integration\fixtures\consume_definitions;

use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;
use illusiard\rabbitmq\tests\integration\fixtures\ConsumeHandler;
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
