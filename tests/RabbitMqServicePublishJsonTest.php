<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\middleware\CorrelationIdMiddleware;
use JsonException;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\definitions\registry\PublisherRegistry;
use illusiard\rabbitmq\profile\RabbitMqProfileInterface;
use illusiard\rabbitmq\tests\fixtures\CustomContentTypeSerializer;
use illusiard\rabbitmq\tests\fixtures\PublishByIdPublisher;
use illusiard\rabbitmq\tests\fixtures\PublisherOnlyConnection;
use ReflectionException;
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

        $connection = new PublisherOnlyConnection($publisher);

        $service = new RabbitMqService([
            'connectionFactory' => fn(array $config) => $connection,
        ]);

        $service->publishJson(['foo' => 'bar'], 'ex', 'rk', [
            'type' => 'type.a',
            'correlationId' => 'corr-1',
            'messageId' => 'msg-json-1',
            'headers' => ['h' => 'v'],
        ]);

        $this->assertSame('ex', $captured['exchange']);
        $this->assertSame('rk', $captured['routingKey']);
        $this->assertSame(['h' => 'v'], $captured['headers']);
        $this->assertSame('application/json', $captured['properties']['content_type']);
        $this->assertSame('corr-1', $captured['properties']['correlation_id']);
        $this->assertSame('msg-json-1', $captured['properties']['message_id']);

        $decoded = json_decode($captured['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['foo' => 'bar'], $decoded['payload']);
        $this->assertSame('type.a', $decoded['type']);
        $this->assertSame('msg-json-1', $decoded['messageId']);
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

        $connection = new PublisherOnlyConnection($publisher);

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
    public function testPublishJsonUsesSerializerContentType(): void
    {
        $captured = [];

        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->willReturnCallback(function ($body, $exchange, $routingKey, $properties, $headers) use (&$captured) {
                $captured = $properties;
            });

        $connection = new PublisherOnlyConnection($publisher);

        $service = new RabbitMqService([
            'serializer' => CustomContentTypeSerializer::class,
            'connectionFactory' => fn(array $config) => $connection,
        ]);

        $service->publishJson('plain body', 'ex', 'rk', [
            'properties' => ['content_type' => 'text/plain'],
        ]);

        $this->assertSame('text/plain', $captured['content_type']);
    }

    /**
     * @return void
     * @throws Exception
     * @throws InvalidConfigException
     * @throws JsonException
     * @throws ReflectionException
     */
    public function testPublishByIdUsesPublisherDefinitionOptionsAndMiddlewares(): void
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

        $connection = new PublisherOnlyConnection($publisher);
        $profile = new class implements RabbitMqProfileInterface {
            public function getConsumerDefaults(): array
            {
                return [];
            }

            public function getPublisherDefaults(): array
            {
                return [
                    'headers' => [
                        'x-service' => 'checkout',
                    ],
                ];
            }
        };
        $service = new class ([
            'profile' => $profile,
            'connectionFactory' => fn(array $config) => $connection,
        ]) extends RabbitMqService {
            public function getPublisherRegistry(): PublisherRegistry
            {
                return new PublisherRegistry([
                    'orders' => PublishByIdPublisher::class,
                ]);
            }
        };

        $service->publishById('orders', ['orderId' => 123], [
            'headers' => [
                'x-request' => 'req-1',
            ],
            'correlationId' => 'corr-1',
            'messageId' => 'msg-by-id-1',
        ]);

        $this->assertSame('orders-exchange', $captured['exchange']);
        $this->assertSame('orders.created', $captured['routingKey']);
        $this->assertSame([
            'x-service' => 'checkout',
            'x-publisher' => 'orders',
            'x-request' => 'req-1',
        ], $captured['headers']);
        $this->assertSame(2, $captured['properties']['delivery_mode']);
        $this->assertSame('corr-1', $captured['properties']['correlation_id']);
        $this->assertSame('msg-by-id-1', $captured['properties']['message_id']);

        $decoded = json_decode($captured['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['orderId' => 123], $decoded['payload']);
        $this->assertSame('order.created', $decoded['type']);
        $this->assertSame('msg-by-id-1', $decoded['messageId']);
    }
}
