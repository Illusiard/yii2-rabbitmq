<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\definitions\publisher\AbstractPublisher;
use illusiard\rabbitmq\middleware\CorrelationIdMiddleware;

class PublishByIdPublisher extends AbstractPublisher
{
    public function getExchange(): string
    {
        return 'orders-exchange';
    }

    public function getRoutingKey(): string
    {
        return 'orders.created';
    }

    public function getOptions(): array
    {
        return [
            'type' => 'order.created',
            'headers' => [
                'x-publisher' => 'orders',
            ],
            'properties' => [
                'delivery_mode' => 2,
            ],
        ];
    }

    public function getMiddlewares(): array
    {
        return [
            CorrelationIdMiddleware::class,
        ];
    }
}
