<?php

namespace illusiard\rabbitmq\tests\fixtures;

use Closure;
use illusiard\rabbitmq\definitions\consumer\AbstractConsumer;

class TopologyConsumerWithMissingQueue extends AbstractConsumer
{
    public function getQueue(): string
    {
        return 'missing-events';
    }

    public function getHandler(): Closure
    {
        return static fn(): bool => true;
    }
}
