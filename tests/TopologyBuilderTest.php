<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\tests\fixtures\TopologyBuilderServiceWithConsumerTopology;
use illusiard\rabbitmq\tests\fixtures\TopologyBuilderServiceWithMissingConsumerQueue;
use JsonException;
use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\topology\TopologyBuilder;
use illusiard\rabbitmq\topology\ExchangeDefinition;
use illusiard\rabbitmq\topology\QueueDefinition;
use ReflectionException;
use yii\base\InvalidConfigException;

class TopologyBuilderTest extends TestCase
{
    /**
     * @return void
     * @throws JsonException
     */
    public function testBuildFromConfigNewFormat(): void
    {
        $config = [
            'exchanges' => [
                [
                    'name' => 'orders-ex',
                    'type' => 'topic',
                    'durable' => false,
                    'autoDelete' => true,
                    'internal' => true,
                    'arguments' => ['alternate-exchange' => 'alt-ex'],
                ],
            ],
            'queues' => [
                [
                    'name' => 'orders-q',
                    'durable' => true,
                    'autoDelete' => false,
                    'exclusive' => false,
                    'arguments' => ['x-max-length' => 10],
                ],
            ],
            'bindings' => [
                [
                    'exchange' => 'orders-ex',
                    'queue' => 'orders-q',
                    'routingKey' => 'orders.*',
                    'arguments' => ['x-match' => 'any'],
                ],
            ],
        ];

        $topology = (new TopologyBuilder())->buildFromConfig($config);

        $exchanges = $this->indexExchanges($topology->getExchanges());
        $queues = $this->indexQueues($topology->getQueues());
        $bindings = $topology->getBindings();

        $this->assertArrayHasKey('orders-ex', $exchanges);
        $this->assertSame('topic', $exchanges['orders-ex']->getType());
        $this->assertFalse($exchanges['orders-ex']->isDurable());
        $this->assertTrue($exchanges['orders-ex']->isAutoDelete());
        $this->assertTrue($exchanges['orders-ex']->isInternal());
        $this->assertSame(['alternate-exchange' => 'alt-ex'], $exchanges['orders-ex']->getArguments());

        $this->assertArrayHasKey('orders-q', $queues);
        $this->assertSame(['x-max-length' => 10], $queues['orders-q']->getArguments());

        $this->assertCount(1, $bindings);
        $this->assertSame('orders-ex', $bindings[0]->getExchange());
        $this->assertSame('orders-q', $bindings[0]->getQueue());
        $this->assertSame('orders.*', $bindings[0]->getRoutingKey());
        $this->assertSame(['x-match' => 'any'], $bindings[0]->getArguments());
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testBuildFromConfigLegacyFormat(): void
    {
        $config = [
            'options' => [
                'retryExchange' => 'retry-ex',
                'retryExchangeType' => 'fanout',
                'retryExchangeDurable' => false,
            ],
            'main' => [
                [
                    'queue' => 'orders',
                    'exchange' => 'orders-ex',
                    'routingKey' => 'orders',
                    'options' => [
                        'exchangeType' => 'direct',
                        'queueArguments' => ['x-max-priority' => 10],
                        'deadLetterRoutingKey' => 'orders.retry',
                    ],
                ],
            ],
            'retry' => [
                [
                    'queue' => 'orders.retry',
                    'ttlMs' => 30000,
                    'deadLetterExchange' => 'orders-ex',
                    'deadLetterRoutingKey' => 'orders',
                ],
            ],
            'dead' => [
                [
                    'queue' => 'orders.dead',
                ],
            ],
        ];

        $topology = (new TopologyBuilder())->buildFromConfig($config);
        $exchanges = $this->indexExchanges($topology->getExchanges());
        $queues = $this->indexQueues($topology->getQueues());

        $this->assertArrayHasKey('orders-ex', $exchanges);
        $this->assertArrayHasKey('retry-ex', $exchanges);

        $this->assertArrayHasKey('orders', $queues);
        $this->assertSame('retry-ex', $queues['orders']->getArguments()['x-dead-letter-exchange']);
        $this->assertSame('orders.retry', $queues['orders']->getArguments()['x-dead-letter-routing-key']);

        $this->assertArrayHasKey('orders.retry', $queues);
        $this->assertSame(30000, $queues['orders.retry']->getArguments()['x-message-ttl']);
        $this->assertSame('orders-ex', $queues['orders.retry']->getArguments()['x-dead-letter-exchange']);

        $this->assertArrayHasKey('orders.dead', $queues);
    }

    /**
     * @return void
     * @throws JsonException
     * @throws ReflectionException
     * @throws InvalidConfigException
     */
    public function testBuildFromServiceIgnoresDefinitionTopology(): void
    {
        $service = new TopologyBuilderServiceWithConsumerTopology();

        $topology = (new TopologyBuilder())->buildFromService($service);

        $exchanges = $this->indexExchanges($topology->getExchanges());
        $this->assertArrayNotHasKey('from-definition', $exchanges);
        $this->assertTrue($topology->hasQueue('events'));
    }

    /**
     * @return void
     * @throws InvalidConfigException
     * @throws JsonException
     * @throws ReflectionException
     */
    public function testBuildFromServiceValidatesConsumerQueues(): void
    {
        $service = new TopologyBuilderServiceWithMissingConsumerQueue();

        $this->expectException(RabbitMqException::class);
        (new TopologyBuilder())->buildFromService($service);
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testBuildFromConfigRejectsMalformedNewFormat(): void
    {
        $this->expectException(RabbitMqException::class);
        $this->expectExceptionMessage('topology.exchanges[0].durable must be a boolean.');

        (new TopologyBuilder())->buildFromConfig([
            'exchanges' => [
                ['name' => 'orders-ex', 'durable' => 'false'],
            ],
        ]);
    }

    private function indexExchanges(array $exchanges): array
    {
        $indexed = [];
        foreach ($exchanges as $exchange) {
            if ($exchange instanceof ExchangeDefinition) {
                $indexed[$exchange->getName()] = $exchange;
            }
        }
        return $indexed;
    }

    private function indexQueues(array $queues): array
    {
        $indexed = [];
        foreach ($queues as $queue) {
            if ($queue instanceof QueueDefinition) {
                $indexed[$queue->getName()] = $queue;
            }
        }
        return $indexed;
    }
}
