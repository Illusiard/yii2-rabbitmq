<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\contracts\ConsumerInterface;
use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\rpc\RpcClient;
use illusiard\rabbitmq\rpc\RpcTimeoutException;

class RpcClientTest extends TestCase
{
    public function testCallPublishesWithReplyToAndCorrelationId(): void
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

        $channel = new FakeRpcChannelTimeout();
        $connection = new FakeRpcConnection($publisher, $channel);

        $service = new RabbitMqService([
            'connectionFactory' => function (array $config) use ($connection) {
                unset($config);
                return $connection;
            },
        ]);

        $client = new RpcClient($service);
        $request = new Envelope(['a' => 1]);

        try {
            $client->call($request, 'ex', 'rk', 1);
            $this->fail('Expected RpcTimeoutException');
        } catch (RpcTimeoutException $e) {
        }

        $this->assertSame('ex', $captured['exchange']);
        $this->assertSame('rk', $captured['routingKey']);
        $this->assertSame('amq.gen-1', $captured['properties']['reply_to']);
        $this->assertNotEmpty($captured['properties']['correlation_id']);
    }
}

class FakeRpcConnection implements ConnectionInterface
{
    private PublisherInterface $publisher;
    private FakeRpcChannelTimeout $channel;
    private bool $connected = false;

    public function __construct(PublisherInterface $publisher, FakeRpcChannelTimeout $channel)
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
            private FakeRpcChannelTimeout $channel;
            private FakeRpcConnection $connection;
            public function __construct(FakeRpcChannelTimeout $channel, FakeRpcConnection $connection)
            {
                $this->channel = $channel;
                $this->connection = $connection;
            }
            public function channel(): FakeRpcChannelTimeout
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

class FakeRpcChannelTimeout
{
    public function queue_declare($queue, $passive, $durable, $exclusive, $autoDelete)
    {
        return ['amq.gen-1', 0, 0];
    }

    public function basic_consume($queue, $tag, $noLocal, $noAck, $exclusive, $noWait, $callback)
    {
        unset($queue, $tag, $noLocal, $noAck, $exclusive, $noWait, $callback);
        return 'ctag-1';
    }

    public function wait($allowed = null, $nonBlocking = false, $timeout = 0)
    {
        throw new AMQPTimeoutException('timeout');
    }

    public function is_open(): bool
    {
        return true;
    }

    public function basic_cancel($tag): void
    {
        unset($tag);
    }

    public function close(): void
    {
    }
}
