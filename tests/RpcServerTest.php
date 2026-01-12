<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\contracts\ConsumerInterface;
use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\rpc\RpcServer;

class RpcServerTest extends TestCase
{
    public function testServePublishesReplyWithCorrelationId(): void
    {
        $captured = [];
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->willReturnCallback(function ($body, $exchange, $routingKey, $properties, $headers = []) use (&$captured) {
                unset($headers);
                $captured = [
                    'body' => $body,
                    'exchange' => $exchange,
                    'routingKey' => $routingKey,
                    'properties' => $properties,
                ];
            });

        $channel = new FakeRpcServerChannel();
        $connection = new FakeRpcServerConnection($publisher, $channel);

        $service = new RabbitMqService([
            'connectionFactory' => function (array $config) use ($connection) {
                unset($config);
                return $connection;
            },
        ]);

        $handlerCalled = false;
        $handler = function (Envelope $request) use (&$handlerCalled) {
            $handlerCalled = true;
            return new Envelope(['ok' => true]);
        };

        $server = new RpcServer($service);
        $server->serve('rpc.queue', $handler);

        $this->assertTrue($handlerCalled);
        $this->assertSame('', $captured['exchange']);
        $this->assertSame('reply.queue', $captured['routingKey']);
        $this->assertSame('corr-1', $captured['properties']['correlation_id']);
        $this->assertSame(1, $channel->acks);
    }
}

class FakeRpcServerConnection implements ConnectionInterface
{
    private PublisherInterface $publisher;
    private FakeRpcServerChannel $channel;
    private bool $connected = false;

    public function __construct(PublisherInterface $publisher, FakeRpcServerChannel $channel)
    {
        $this->publisher = $publisher;
        $this->channel = $channel;
        $this->connected = true;
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

    public function getAmqpConnection(): object
    {
        return new class($this->channel, $this) {
            private FakeRpcServerChannel $channel;
            private FakeRpcServerConnection $connection;
            public function __construct(FakeRpcServerChannel $channel, FakeRpcServerConnection $connection)
            {
                $this->channel = $channel;
                $this->connection = $connection;
            }
            public function channel(): FakeRpcServerChannel
            {
                return $this->channel;
            }
            public function isConnected(): bool
            {
                return $this->connection->isConnected();
            }
        };
    }
}

class FakeRpcServerChannel
{
    public int $acks = 0;
    private bool $consuming = true;

    public function basic_consume($queue, $tag, $noLocal, $noAck, $exclusive, $noWait, $callback)
    {
        unset($queue, $tag, $noLocal, $noAck, $exclusive, $noWait);
        $message = new FakeRpcMessage($this);
        $callback($message);
        $this->consuming = false;
    }

    public function is_consuming(): bool
    {
        return $this->consuming;
    }

    public function wait(): void
    {
    }

    public function basic_ack($deliveryTag): void
    {
        unset($deliveryTag);
        $this->acks++;
    }

    public function basic_reject($deliveryTag, $requeue): void
    {
        unset($deliveryTag, $requeue);
    }

    public function is_open(): bool
    {
        return true;
    }

    public function close(): void
    {
    }
}

class FakeRpcMessage
{
    private FakeRpcServerChannel $channel;

    public function __construct(FakeRpcServerChannel $channel)
    {
        $this->channel = $channel;
    }

    public function get_properties(): array
    {
        return [
            'reply_to' => 'reply.queue',
            'correlation_id' => 'corr-1',
        ];
    }

    public function getBody(): string
    {
        return json_encode([
            'messageId' => 'm1',
            'correlationId' => 'corr-1',
            'type' => 'rpc.request',
            'timestamp' => 1700000000,
            'payload' => ['ping' => true],
            'headers' => [],
            'properties' => [],
        ]);
    }

    public function getChannel(): FakeRpcServerChannel
    {
        return $this->channel;
    }

    public function getDeliveryTag(): int
    {
        return 1;
    }
}
