<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\rpc\RpcServer;
use illusiard\rabbitmq\tests\fixtures\FakeRpcServerChannel;
use illusiard\rabbitmq\tests\fixtures\FakeRpcServerConnection;

class RpcServerTest extends TestCase
{
    public function testServePublishesReplyWithCorrelationId(): void
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

        $channel = new FakeRpcServerChannel();
        $connection = new FakeRpcServerConnection($publisher, $channel);

        $service = new RabbitMqService([
            'connectionFactory' => fn(array $config) => $connection,
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
