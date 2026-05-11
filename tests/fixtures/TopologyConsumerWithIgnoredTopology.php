<?php

namespace illusiard\rabbitmq\tests\fixtures;

use Closure;
use illusiard\rabbitmq\definitions\consumer\AbstractConsumer;

class TopologyConsumerWithIgnoredTopology extends AbstractConsumer
{
    public function getQueue(): string
    {
        return 'events';
    }

    public function getHandler(): Closure
    {
        return static fn(): bool => true;
    }

    public function getOptions(): array
    {
        return [
            'topology' => [
                'exchanges' => [
                    ['name' => 'from-definition'],
                ],
            ],
        ];
    }
}
