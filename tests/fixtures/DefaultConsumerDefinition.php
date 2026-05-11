<?php

namespace illusiard\rabbitmq\tests\fixtures;

use Closure;
use illusiard\rabbitmq\definitions\consumer\AbstractConsumer;

class DefaultConsumerDefinition extends AbstractConsumer
{
    public function getQueue(): string
    {
        return 'queue';
    }

    public function getHandler(): Closure
    {
        return static fn(): bool => true;
    }
}
