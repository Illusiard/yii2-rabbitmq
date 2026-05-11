<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\rpc\RpcClient;
use illusiard\rabbitmq\rpc\RpcTimeoutException;
use illusiard\rabbitmq\tests\fixtures\FakeRpcChannelTimeout;
use illusiard\rabbitmq\tests\fixtures\FakeRpcConnection;

class RpcClientTest extends TestCase
{
    public function testCallPublishesWithReplyToAndCorrelationId(): void
    {
        $captured = [];
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->willReturnCallback(function ($body, $exchange, $routingKey, $properties, $headers = []) use (&$captured) {
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
                return $connection;
            },
        ]);

        $client = new RpcClient($service);
        $request = new Envelope(['a' => 1]);

        try {
            $client->call($request, 'ex', 'rk', 1);
            $this->fail('Expected RpcTimeoutException');
        } catch (RpcTimeoutException) {
        }

        $this->assertSame('ex', $captured['exchange']);
        $this->assertSame('rk', $captured['routingKey']);
        $this->assertSame('amq.gen-1', $captured['properties']['reply_to']);
        $this->assertNotEmpty($captured['properties']['correlation_id']);
    }
}
