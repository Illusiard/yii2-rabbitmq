<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\dlq\DlqService;
use illusiard\rabbitmq\tests\fixtures\FakeDlqChannel;
use illusiard\rabbitmq\tests\fixtures\FakeDlqConnection;
use illusiard\rabbitmq\tests\fixtures\FakeDlqMessage;
use ReflectionException;
use ReflectionMethod;

class DlqServiceTest extends TestCase
{
    public function testInspectNonDestructiveRejectsAndDoesNotAck(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $channel = new FakeDlqChannel([]);
        $channel->setMessages([
            new FakeDlqMessage('m1', 'c1', null),
        ]);
        $channel->attachSelfToMessages();

        $connection = new FakeDlqConnection($publisher, $channel);
        $service = new RabbitMqService([
            'connectionFactory' => fn(array $config) => $connection,
        ]);

        $dlq = new DlqService($service);
        $dlq->inspect('dlq.queue', 1);

        $this->assertSame(0, $channel->acks);
        $this->assertSame(1, $channel->rejects);
        $this->assertTrue($channel->lastRejectRequeue);
    }

    public function testInspectDestructiveAcks(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $channel = new FakeDlqChannel([]);
        $channel->setMessages([
            new FakeDlqMessage('m1', 'c1', null),
        ]);
        $channel->attachSelfToMessages();

        $connection = new FakeDlqConnection($publisher, $channel);
        $service = new RabbitMqService([
            'connectionFactory' => fn(array $config) => $connection,
        ]);

        $dlq = new DlqService($service);
        $dlq->inspect('dlq.queue', 1, true);

        $this->assertSame(1, $channel->acks);
        $this->assertSame(0, $channel->rejects);
    }

    /**
     * @return void
     * @throws Exception
     * @throws ReflectionException
     */
    public function testFingerprintUsesMessageIdAndHeaderHashFallback(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $channel = new FakeDlqChannel([]);
        $connection = new FakeDlqConnection($publisher, $channel);
        $service = new RabbitMqService([
            'connectionFactory' => fn(array $config) => $connection,
        ]);

        $dlq = new DlqService($service);
        $method = new ReflectionMethod(DlqService::class, 'buildFingerprint');
        $method->setAccessible(true);

        $byId = $method->invoke($dlq, 'body', [], ['message_id' => 'msg-1']);
        $this->assertSame('message_id:msg-1', $byId);

        $headersA = ['b' => 2, 'a' => 1];
        $headersB = ['a' => 1, 'b' => 2];
        $hashA = $method->invoke($dlq, 'body', $headersA, []);
        $hashB = $method->invoke($dlq, 'body', $headersB, []);
        $this->assertSame($hashA, $hashB);
    }

    public function testReplayPublishesAndAcks(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->exactly(2))->method('publish');

        $channel = new FakeDlqChannel([]);
        $channel->setMessages([
            new FakeDlqMessage('m1', 'c1', null),
            new FakeDlqMessage('m2', 'c2', null),
        ]);
        $channel->attachSelfToMessages();

        $connection = new FakeDlqConnection($publisher, $channel);
        $service = new RabbitMqService([
            'connectionFactory' => fn(array $config) => $connection,
        ]);

        $dlq = new DlqService($service);
        $count = $dlq->replay('dlq.queue', 'ex', 'rk', 10);

        $this->assertSame(2, $count);
        $this->assertSame(2, $channel->acks);
    }

    public function testPurgeCallsQueuePurge(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $channel = new FakeDlqChannel([]);
        $connection = new FakeDlqConnection($publisher, $channel);

        $service = new RabbitMqService([
            'connectionFactory' => fn(array $config) => $connection,
        ]);

        $dlq = new DlqService($service);
        $dlq->purge('dlq.queue');

        $this->assertSame(1, $channel->purges);
        $this->assertSame('dlq.queue', $channel->lastPurgeQueue);
    }
}
