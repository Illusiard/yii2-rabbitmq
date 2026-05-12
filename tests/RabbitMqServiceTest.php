<?php

namespace illusiard\rabbitmq\tests;

use JsonException;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use RuntimeException;
use Yii;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\tests\fixtures\TopologyBuilderServiceWithMissingConsumerQueue;
use illusiard\rabbitmq\topology\ExchangeDefinition;
use illusiard\rabbitmq\topology\QueueDefinition;
use illusiard\rabbitmq\topology\Topology;
use yii\base\InvalidConfigException;

class RabbitMqServiceTest extends TestCase
{
    /**
     * @return void
     * @throws InvalidConfigException
     */
    protected function setUp(): void
    {
        parent::setUp();
        Yii::$app->set('rabbitmq', [
            'class' => RabbitMqService::class,
        ]);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testWiringResolvesComponent(): void
    {
        $service = Yii::$app->get('rabbitmq');

        $this->assertInstanceOf(RabbitMqService::class, $service);
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testLazyConnectionAndPublish(): void
    {
        $publisherCalls = 0;

        $publisher = $this->createMock(PublisherInterface::class);
        $publisher
            ->expects($this->once())
            ->method('publish')
            ->willReturnCallback(function () use (&$publisherCalls) {
                $publisherCalls++;
            });

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())->method('isConnected')->willReturn(true);
        $connection->expects($this->never())->method('connect');
        $connection->expects($this->once())->method('getPublisher')->willReturn($publisher);

        $factoryCalls = 0;
        $service = new RabbitMqService([
            'connectionFactory' => function (array $config) use ($connection, &$factoryCalls) {
                $factoryCalls++;
                return $connection;
            },
        ]);

        $this->assertSame(0, $factoryCalls);
        $this->assertSame(0, $publisherCalls);

        $service->publish('body', 'exchange', 'rk');

        $this->assertSame(1, $factoryCalls);
        $this->assertSame(1, $publisherCalls);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     * @throws JsonException
     * @throws ReflectionException
     */
    public function testSetupTopologyDryRunOnlyValidates(): void
    {
        $factoryCalls = 0;
        $service = new RabbitMqService([
            'connectionFactory' => function (array $config) use (&$factoryCalls) {
                $factoryCalls++;
                throw new RuntimeException('Connection must not be created for setupTopology dry-run.');
            },
        ]);

        $service->setupTopology([
            'exchanges' => [
                ['name' => 'orders-ex'],
            ],
            'queues' => [
                ['name' => 'orders'],
            ],
            'bindings' => [
                ['exchange' => 'orders-ex', 'queue' => 'orders', 'routingKey' => 'orders'],
            ],
        ], true);

        $this->assertSame(0, $factoryCalls);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testApplyTopologyDryRunDoesNotCreateConnection(): void
    {
        $factoryCalls = 0;
        $service = new RabbitMqService([
            'connectionFactory' => function (array $config) use (&$factoryCalls) {
                $factoryCalls++;
                throw new RuntimeException('Connection must not be created for applyTopology dry-run.');
            },
        ]);
        $topology = new Topology();
        $topology->addExchange(new ExchangeDefinition('orders-ex'));
        $topology->addQueue(new QueueDefinition('orders'));

        $service->applyTopology($topology, true);

        $this->assertSame(0, $factoryCalls);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     * @throws JsonException
     * @throws ReflectionException
     */
    public function testSetupTopologyValidatesDiscoveredConsumerQueues(): void
    {
        $service = new TopologyBuilderServiceWithMissingConsumerQueue();

        $this->expectException(RabbitMqException::class);
        $this->expectExceptionMessage("Consumer queue 'missing-events'");

        $service->setupTopology([
            'queues' => [
                ['name' => 'events'],
            ],
        ], true);
    }
}
