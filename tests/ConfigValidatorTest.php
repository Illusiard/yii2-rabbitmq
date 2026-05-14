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

    public function testManagedRetryRequiresValidRetryPolicy(): void
    {
        $validator = new ConfigValidator();

        $this->expectException(RabbitMqException::class);
        $this->expectExceptionMessage('retryPolicy.maxAttempts must be a positive integer.');

        $validator->validate([
            'amqp' => [
                'host' => '127.0.0.1',
                'port' => 5672,
                'user' => 'guest',
                'password' => 'guest',
            ],
            'managedRetry' => true,
            'retryPolicy' => [
                'retryQueues' => [
                    ['name' => 'orders.retry.1', 'ttlMs' => 5000],
                ],
            ],
        ]);
    }

    public function testSslConfigAllowsStreamContextOptions(): void
    {
        $validator = new ConfigValidator();
        $validator->validate([
            'amqp' => [
                'host' => '127.0.0.1',
                'port' => 5672,
                'user' => 'guest',
                'password' => 'guest',
                'ssl' => [
                    'cafile' => '/path/to/ca.pem',
                    'verify_peer' => true,
                    'verify_depth' => 3,
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
                ],
            ],
        ]);

        $this->assertTrue(true);
    }

    public function testSslConfigMustBeArrayOrNull(): void
    {
        $validator = new ConfigValidator();

        $this->expectException(RabbitMqException::class);
        $this->expectExceptionMessage('amqp.ssl must be an array or null.');

        $validator->validate([
            'amqp' => [
                'host' => '127.0.0.1',
                'port' => 5672,
                'user' => 'guest',
                'password' => 'guest',
                'ssl' => 'enabled',
            ],
        ]);
    }

    public function testReconnectAttemptsMustBePositive(): void
    {
        $validator = new ConfigValidator();

        $this->expectException(RabbitMqException::class);
        $this->expectExceptionMessage('amqp.consumeReconnectAttempts must be >= 1.');

        $validator->validate([
            'amqp' => [
                'host' => '127.0.0.1',
                'port' => 5672,
                'user' => 'guest',
                'password' => 'guest',
                'consumeReconnectAttempts' => 0,
            ],
        ]);
    }

    public function testMessageLimitExceededActionMustBeKnown(): void
    {
        $validator = new ConfigValidator();

        $this->expectException(RabbitMqException::class);
        $this->expectExceptionMessage('messageLimitExceededAction must be reject, retry, or stop.');

        $validator->validate([
            'amqp' => [
                'host' => '127.0.0.1',
                'port' => 5672,
                'user' => 'guest',
                'password' => 'guest',
            ],
            'messageLimitExceededAction' => 'drop',
        ]);
    }
}
