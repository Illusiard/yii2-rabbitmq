<?php

namespace illusiard\rabbitmq\tests\fixtures;

use Closure;
use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;

class RetryTestConsumer implements ConsumerInterface
{
    public function getQueue(): string
    {
        return 'queue';
    }

    public function getHandler(): Closure
    {
        return static fn(): bool => false;
    }

    public function getOptions(): array
    {
        return [];
    }

    public function getMiddlewares(): array
    {
        return [];
    }
}
