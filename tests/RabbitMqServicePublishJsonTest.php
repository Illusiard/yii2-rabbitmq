<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\middleware\CorrelationIdMiddleware;
use JsonException;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\contracts\ConsumerInterface;
use RuntimeException;
use yii\base\InvalidConfigException;

class RabbitMqServicePublishJsonTest extends TestCase
{
    /**
     * @return void
     * @throws Exception
     * @throws InvalidConfigException
     * @throws JsonException
     */
    public function testPublishJsonBuildsEnvelope(): void
    {
        $captured = [];

        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->willReturnCallback(function ($body, $exchange, $routingKey, $properties, $headers) use (&$captured) {
                $captured = [
                    'body' => $body,
                    'exchange' => $exchange,
                    'routingKey' => $routingKey,
                    'properties' => $properties,
                    'headers' => $headers,
                ];
            });

        $connection = new class($publisher) implements ConnectionInterface {
            private PublisherInterface $publisher;
            public function __construct(PublisherInterface $publisher)
            {
                $this->publisher = $publisher;
            }
            public function connect(): void
            {
            }
            public function isConnected(): bool
            {
                return true;
            }
            public function close(): void
            {
            }
            public function getPublisher(): PublisherInterface
            {
                return $this->publisher;
            }
            public function getConsumer(): ConsumerInterface
            {
                throw new RuntimeException('Not implemented.');
            }
        };

        $service = new RabbitMqService([
            'connectionFactory' => fn(array $config) => $connection,
        ]);

        $service->publishJson(['foo' => 'bar'], 'ex', 'rk', [
            'type' => 'type.a',
            'correlationId' => 'corr-1',
            'headers' => ['h' => 'v'],
        ]);

        $this->assertSame('ex', $captured['exchange']);
        $this->assertSame('rk', $captured['routingKey']);
        $this->assertSame(['h' => 'v'], $captured['headers']);
        $this->assertSame('application/json', $captured['properties']['content_type']);
        $this->assertSame('corr-1', $captured['properties']['correlation_id']);
        $this->assertNotEmpty($captured['properties']['message_id']);

        $decoded = json_decode($captured['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['foo' => 'bar'], $decoded['payload']);
        $this->assertSame('type.a', $decoded['type']);
    }

    /**
     * @throws InvalidConfigException
     */
    public function testPublishJsonRunsPublishMiddleware(): void
    {
        $captured = [];

        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->willReturnCallback(function ($body, $exchange, $routingKey, $properties, $headers) use (&$captured) {
                $captured = [
                    'body' => $body,
                    'exchange' => $exchange,
                    'routingKey' => $routingKey,
                    'properties' => $properties,
                    'headers' => $headers,
                ];
            });

        $connection = new class($publisher) implements ConnectionInterface {
            private PublisherInterface $publisher;
            public function __construct(PublisherInterface $publisher)
            {
                $this->publisher = $publisher;
            }
            public function connect(): void
            {
            }
            public function isConnected(): bool
            {
                return true;
            }
            public function close(): void
            {
            }
            public function getPublisher(): PublisherInterface
            {
                return $this->publisher;
            }
            public function getConsumer(): ConsumerInterface
            {
                throw new RuntimeException('Not implemented.');
            }
        };

        $service = new RabbitMqService([
            'publishMiddlewares' => [
                CorrelationIdMiddleware::class,
            ],
            'connectionFactory' => fn(array $config) => $connection,
        ]);

        $service->publishJson(['foo' => 'bar'], 'ex', 'rk', [
            'headers' => ['h' => 'v'],
        ]);

        $this->assertSame('ex', $captured['exchange']);
        $this->assertSame('rk', $captured['routingKey']);
        $this->assertSame(['h' => 'v'], $captured['headers']);
        $this->assertNotEmpty($captured['properties']['correlation_id']);
    }

    /**
     * @return void
     * @throws Exception
     * @throws InvalidConfigException
     */
    public function testPublishJsonForcesJsonContentType(): void
    {
        $captured = [];

        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->willReturnCallback(function ($body, $exchange, $routingKey, $properties, $headers) use (&$captured) {
                $captured = $properties;
            });

        $connection = new class($publisher) implements ConnectionInterface {
            private PublisherInterface $publisher;
            public function __construct(PublisherInterface $publisher)
            {
                $this->publisher = $publisher;
            }
            public function connect(): void
            {
            }
            public function isConnected(): bool
            {
                return true;
            }
            public function close(): void
            {
            }
            public function getPublisher(): PublisherInterface
            {
                return $this->publisher;
            }
            public function getConsumer(): ConsumerInterface
            {
                throw new RuntimeException('Not implemented.');
            }
        };

        $service = new RabbitMqService([
            'connectionFactory' => fn(array $config) => $connection,
        ]);

        $service->publishJson(['foo' => 'bar'], 'ex', 'rk', [
            'properties' => ['content_type' => 'text/plain'],
        ]);

        $this->assertSame('application/json', $captured['content_type']);
    }
}
