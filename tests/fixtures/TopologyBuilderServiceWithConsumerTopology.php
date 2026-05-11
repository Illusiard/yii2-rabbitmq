<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;
use illusiard\rabbitmq\definitions\registry\ConsumerRegistry;

class TopologyBuilderServiceWithConsumerTopology extends RabbitMqService
{
    public array $topology = [
        'queues' => [
            ['name' => 'events'],
        ],
    ];

    public function getConsumerRegistry(): ConsumerRegistry
    {
        return new ConsumerRegistry([
            'events' => 'app\\services\\rabbitmq\\consumers\\EventsConsumer',
        ]);
    }

    public function createConsumerDefinition(string $fqcn): ConsumerInterface
    {
        return new TopologyConsumerWithIgnoredTopology();
    }
}
