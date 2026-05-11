<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\tests\fixtures\HealthcheckFakeConnection;
use illusiard\rabbitmq\tests\fixtures\HealthcheckFakeConnectionWithChannel;
use illusiard\rabbitmq\tests\fixtures\HealthcheckThrowingConnection;

class ProfileHealthcheckTest extends TestCase
{
    public function testProfilesSelectConfig(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->exactly(2))->method('publish');

        $usedConfigs = [];
        $factory = static function (array $config) use (&$usedConfigs, $publisher) {
            $usedConfigs[] = $config;
            return new HealthcheckFakeConnection($publisher);
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
        $connection = new HealthcheckFakeConnectionWithChannel($this->createMock(PublisherInterface::class));
        $factory = static fn(array $config) => $connection;

        $service = new RabbitMqService([
            'connectionFactory' => $factory,
        ]);

        $this->assertTrue($service->ping(1));
        $this->assertNull($service->getLastError());
        $this->assertSame(1, $connection->channelCloses);
    }

    public function testPingFailure(): void
    {
        $factory = static fn(array $config) => new HealthcheckThrowingConnection();

        $service = new RabbitMqService([
            'connectionFactory' => $factory,
        ]);

        $this->assertFalse($service->ping(1));
        $this->assertSame('GENERIC RuntimeException: Connect failed', $service->getLastError());
    }
}
