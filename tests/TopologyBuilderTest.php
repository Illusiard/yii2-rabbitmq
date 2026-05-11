<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\topology\TopologyBuilder;
use illusiard\rabbitmq\topology\ExchangeDefinition;
use illusiard\rabbitmq\topology\QueueDefinition;

class TopologyBuilderTest extends TestCase
{
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

    public function testBuildFromServiceIgnoresDefinitionTopology(): void
    {
        $service = new class extends \illusiard\rabbitmq\components\RabbitMqService {
            public function getConsumerRegistry(): \illusiard\rabbitmq\definitions\registry\ConsumerRegistry
            {
                return new \illusiard\rabbitmq\definitions\registry\ConsumerRegistry([
                    'events' => 'app\\services\\rabbitmq\\consumers\\EventsConsumer',
                ]);
            }

            public function createConsumerDefinition(string $fqcn): \illusiard\rabbitmq\definitions\consumer\ConsumerInterface
            {
                unset($fqcn);

                return new class extends \illusiard\rabbitmq\definitions\consumer\AbstractConsumer {
                    public function getQueue(): string
                    {
                        return 'events';
                    }

                    public function getHandler()
                    {
                        return function (): bool {
                            return true;
                        };
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
                };
            }
        };

        $topology = (new TopologyBuilder())->buildFromService($service);

        $this->assertTrue($topology->isEmpty());
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
