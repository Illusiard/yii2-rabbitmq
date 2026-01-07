<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\contracts\ConsumerInterface;

class ProfileHealthcheckTest extends TestCase
{
    public function testProfilesSelectConfig(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->exactly(2))->method('publish');

        $usedConfigs = [];
        $factory = function (array $config) use (&$usedConfigs, $publisher) {
            $usedConfigs[] = $config;
            return new FakeConnection($publisher);
        };

        $service = new RabbitMqService([
            'profiles' => [
                'default' => ['host' => 'a'],
                'secondary' => ['host' => 'b'],
            ],
            'defaultProfile' => 'default',
            'connectionFactory' => $factory,
        ]);

        $service->publish('body', 'ex', 'rk');
        $service->forProfile('secondary')->publish('body', 'ex', 'rk');

        $this->assertSame('a', $usedConfigs[0]['host']);
        $this->assertSame('b', $usedConfigs[1]['host']);
    }

    public function testPingSuccess(): void
    {
        $connection = new FakeConnectionWithChannel($this->createMock(PublisherInterface::class));
        $factory = function (array $config) use ($connection) {
            unset($config);
            return $connection;
        };

        $service = new RabbitMqService([
            'connectionFactory' => $factory,
        ]);

        $this->assertTrue($service->ping(1));
        $this->assertNull($service->getLastError());
        $this->assertSame(1, $connection->channelCloses);
    }

    public function testPingFailure(): void
    {
        $factory = function (array $config) {
            unset($config);
            return new ThrowingConnection();
        };

        $service = new RabbitMqService([
            'connectionFactory' => $factory,
        ]);

        $this->assertFalse($service->ping(1));
        $this->assertSame('GENERIC RuntimeException: Connect failed', $service->getLastError());
    }
}

class FakeConnection implements ConnectionInterface
{
    private PublisherInterface $publisher;
    private bool $connected = false;

    public function __construct(PublisherInterface $publisher)
    {
        $this->publisher = $publisher;
    }

    public function connect(): void
    {
        $this->connected = true;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function getPublisher(): PublisherInterface
    {
        return $this->publisher;
    }

    public function getConsumer(): ConsumerInterface
    {
        throw new \RuntimeException('Not implemented.');
    }
}

class FakeConnectionWithChannel extends FakeConnection
{
    public int $channelCloses = 0;

    public function getAmqpConnection(): object
    {
        return new class($this) {
            private FakeConnectionWithChannel $parent;

            public function __construct(FakeConnectionWithChannel $parent)
            {
                $this->parent = $parent;
            }

            public function channel(): object
            {
                return new class($this->parent) {
                    private FakeConnectionWithChannel $parent;

                    public function __construct(FakeConnectionWithChannel $parent)
                    {
                        $this->parent = $parent;
                    }

                    public function close(): void
                    {
                        $this->parent->channelCloses++;
                    }
                };
            }
        };
    }
}

class ThrowingConnection implements ConnectionInterface
{
    public function connect(): void
    {
        throw new \RuntimeException('Connect failed');
    }

    public function isConnected(): bool
    {
        return false;
    }

    public function close(): void
    {
    }

    public function getPublisher(): PublisherInterface
    {
        throw new \RuntimeException('Not implemented.');
    }

    public function getConsumer(): ConsumerInterface
    {
        throw new \RuntimeException('Not implemented.');
    }
}
