<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\amqp\AmqpConsumer;
use illusiard\rabbitmq\amqp\AmqpConnection;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\retry\RetryDecision;

class ManagedRetryTest extends TestCase
{
    public function testRetryDecisionIncrementsRetryCountAndPreservesHeaders(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'body',
                '',
                'orders.retry.30s',
                $this->anything(),
                $this->callback(function (array $headers): bool {
                    return isset($headers['x-retry-count'])
                        && $headers['x-retry-count'] === 3
                        && isset($headers['x-trace-id'])
                        && $headers['x-trace-id'] === 'trace-1';
                })
            );

        $consumer = $this->buildConsumer($publisher);

        $acks = 0;
        $rejects = 0;
        $consumer->testHandleDecision(
            RetryDecision::retry('orders.retry.30s'),
            'body',
            [
                'headers' => [
                    'x-retry-count' => 2,
                    'x-trace-id' => 'trace-1',
                ],
            ],
            function () use (&$acks) {
                $acks++;
            },
            function () use (&$rejects) {
                $rejects++;
            }
        );

        $this->assertSame(1, $acks);
        $this->assertSame(0, $rejects);
    }

    public function testRetryDecisionRepublishesAndAcks(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'body',
                '',
                'orders.retry.5s',
                $this->anything(),
                $this->callback(function (array $headers): bool {
                    return isset($headers['x-retry-count']) && $headers['x-retry-count'] === 1;
                })
            );

        $consumer = $this->buildConsumer($publisher);

        $acks = 0;
        $rejects = 0;
        $consumer->testHandleDecision(
            RetryDecision::retry('orders.retry.5s'),
            'body',
            [
                'headers' => [],
            ],
            function () use (&$acks) {
                $acks++;
            },
            function () use (&$rejects) {
                $rejects++;
            }
        );

        $this->assertSame(1, $acks);
        $this->assertSame(0, $rejects);
    }

    public function testDeadDecisionRepublishesAndAcks(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'body',
                '',
                'orders.dead',
                $this->anything(),
                $this->callback(function (array $headers): bool {
                    return isset($headers['x-retry-count']) && $headers['x-retry-count'] === 2;
                })
            );

        $consumer = $this->buildConsumer($publisher);

        $acks = 0;
        $rejects = 0;
        $consumer->testHandleDecision(
            RetryDecision::dead('orders.dead'),
            'body',
            [
                'headers' => [
                    'x-retry-count' => 2,
                ],
            ],
            function () use (&$acks) {
                $acks++;
            },
            function () use (&$rejects) {
                $rejects++;
            }
        );

        $this->assertSame(1, $acks);
        $this->assertSame(0, $rejects);
    }

    public function testDeadDecisionWithoutQueueRejects(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $consumer = $this->buildConsumer($publisher);

        $acks = 0;
        $rejects = 0;
        $consumer->testHandleDecision(
            RetryDecision::dead(null),
            'body',
            [
                'headers' => [
                    'x-retry-count' => 1,
                ],
            ],
            function () use (&$acks) {
                $acks++;
            },
            function () use (&$rejects) {
                $rejects++;
            }
        );

        $this->assertSame(0, $acks);
        $this->assertSame(1, $rejects);
    }

    private function buildConsumer(PublisherInterface $publisher): TestAmqpConsumer
    {
        $connection = $this->createMock(AmqpConnection::class);
        $consumer = new TestAmqpConsumer($connection);
        $consumer->setManagedRetry(true, [], $publisher);

        return $consumer;
    }
}

class TestAmqpConsumer extends AmqpConsumer
{
    public function testHandleDecision(
        RetryDecision $decision,
        string $body,
        array $meta,
        callable $ack,
        callable $reject
    ): void {
        $this->handleManagedDecision($decision, $body, $meta, $ack, $reject);
    }
}
