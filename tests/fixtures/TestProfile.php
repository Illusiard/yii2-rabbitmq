<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\profile\OptionsMerger;
use illusiard\rabbitmq\profile\RabbitMqProfileInterface;

class TestProfile implements RabbitMqProfileInterface
{
    public function getConsumerDefaults(): array
    {
        return [
            'prefetch' => 5,
            'retryPolicy' => [
                'maxAttempts' => 3,
                'delaySeconds' => 5,
            ],
        ];
    }

    public function getPublisherDefaults(): array
    {
        return [
            'confirm' => false,
            'headers' => [
                'x-service' => 'test',
            ],
        ];
    }

    public function getMiddlewareDefaults(): array
    {
        return [
            'consumer' => ['base-consume'],
            'publisher' => ['base-publish'],
        ];
    }

    public function mergeOptions(array $defaults, array $overrides): array
    {
        return OptionsMerger::merge($defaults, $overrides);
    }
}
