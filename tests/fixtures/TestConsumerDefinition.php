<?php

namespace illusiard\rabbitmq\tests\fixtures;

use Closure;
use illusiard\rabbitmq\definitions\consumer\AbstractConsumer;

class TestConsumerDefinition extends AbstractConsumer
{
    public function getQueue(): string
    {
        return 'queue';
    }

    public function getHandler(): Closure
    {
        return static fn(): bool => true;
    }

    public function getOptions(): array
    {
        return [
            'prefetch' => 1,
            'retryPolicy' => [
                'maxAttempts' => null,
                'delaySeconds' => 10,
            ],
        ];
    }

    public function getMiddlewares(): array
    {
        return ['consume-extra'];
    }
}
