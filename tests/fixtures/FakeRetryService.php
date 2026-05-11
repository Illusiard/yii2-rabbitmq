<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\components\RabbitMqService;

class FakeRetryService extends RabbitMqService
{
    public array $published = [];

    public function publish(
        string $body,
        string $exchange = '',
        string $routingKey = '',
        array $properties = [],
        array $headers = []
    ): void {
        $this->published[] = [
            'body' => $body,
            'exchange' => $exchange,
            'routingKey' => $routingKey,
            'properties' => $properties,
            'headers' => $headers,
        ];
    }
}
