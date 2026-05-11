<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\config\ConfigValidator;
use illusiard\rabbitmq\exceptions\RabbitMqException;

class ConfigValidatorTest extends TestCase
{
    public function testValidConfig(): void
    {
        $validator = new ConfigValidator();
        $validator->validate([
            'amqp' => [
                'host' => '127.0.0.1',
                'port' => 5672,
                'user' => 'guest',
                'password' => 'guest',
                'vhost' => '/',
                'heartbeat' => 30,
                'readWriteTimeout' => 3,
                'connectionTimeout' => 3,
                'confirm' => false,
                'mandatory' => false,
                'publishTimeout' => 5,
            ],
            'publishMiddlewares' => [],
            'consumeMiddlewares' => [],
        ]);

        $this->assertTrue(true);
    }

    public function testInvalidPort(): void
    {
        $validator = new ConfigValidator();

        $this->expectException(RabbitMqException::class);
        $this->expectExceptionMessage('amqp.port must be between 1 and 65535.');

        $validator->validate([
            'amqp' => [
                'host' => '127.0.0.1',
                'port' => 70000,
                'user' => 'guest',
                'password' => 'guest',
            ],
        ]);
    }

    public function testInvalidNewTopologyFormatFailsValidation(): void
    {
        $validator = new ConfigValidator();

        $this->expectException(RabbitMqException::class);
        $this->expectExceptionMessage('topology.bindings[0].queue must be a non-empty string.');

        $validator->validate([
            'amqp' => [
                'host' => '127.0.0.1',
                'port' => 5672,
                'user' => 'guest',
                'password' => 'guest',
            ],
            'topology' => [
                'exchanges' => [
                    ['name' => 'orders-ex'],
                ],
                'queues' => [
                    ['name' => 'orders'],
                ],
                'bindings' => [
                    ['exchange' => 'orders-ex', 'routingKey' => 'orders'],
                ],
            ],
        ]);
    }
}
