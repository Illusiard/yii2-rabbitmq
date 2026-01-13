<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\amqp\InMemoryReturnSink;
use illusiard\rabbitmq\amqp\ReturnedMessage;

class ReturnSinkTest extends TestCase
{
    public function testInMemoryReturnSinkBuffersAndDropsOldest(): void
    {
        $sink = new InMemoryReturnSink(2);
        $sink->onReturned($this->createEvent('m1'));
        $sink->onReturned($this->createEvent('m2'));
        $sink->onReturned($this->createEvent('m3'));

        $stats = $sink->getStats();
        $this->assertSame(3, $stats['totalReturns']);
        $this->assertSame(1, $stats['droppedReturns']);
        $this->assertSame(2, $stats['bufferedReturns']);

        $items = $sink->drainReturns();
        $this->assertCount(2, $items);
        $this->assertSame('m2', $items[0]->messageId);
        $this->assertSame('m3', $items[1]->messageId);
    }

    public function testInMemoryReturnSinkTracksAckNackStats(): void
    {
        $sink = new InMemoryReturnSink(1);
        $sink->onAck(1, false);
        $sink->onNack(2, true);

        $stats = $sink->getStats();
        $this->assertSame(1, $stats['ackCount']);
        $this->assertSame(1, $stats['nackCount']);
    }

    private function createEvent(string $messageId): ReturnedMessage
    {
        return new ReturnedMessage(
            $messageId,
            null,
            'ex',
            'rk',
            312,
            'NO_ROUTE',
            [],
            [],
            0,
            microtime(true)
        );
    }
}
