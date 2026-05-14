<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\contracts\ConsumerInterface;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;

class RunnerMiddlewareConsumer implements ConsumerInterface
{
    public static ?string $lastAction = null;

    public function consume(string $queue, callable $handler, int $prefetch = 1): void
    {
        $result = $handler('{"ok":true}', [
            'headers' => [],
            'properties' => [],
            'delivery_tag' => 1,
            'routing_key' => 'routing',
            'exchange' => 'exchange',
            'redelivered' => false,
        ]);

        self::$lastAction = ConsumeResult::normalizeHandlerResult($result)->getAction();
    }
}
