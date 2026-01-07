<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use Yii;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;

class RabbitMqServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Yii::$app->set('rabbitmq', [
            'class' => RabbitMqService::class,
        ]);
    }

    public function testWiringResolvesComponent(): void
    {
        $service = Yii::$app->get('rabbitmq');

        $this->assertInstanceOf(RabbitMqService::class, $service);
    }

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
                unset($config);
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
}
