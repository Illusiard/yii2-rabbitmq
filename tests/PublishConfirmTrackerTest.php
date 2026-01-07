<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\amqp\PublishConfirmTracker;

class PublishConfirmTrackerTest extends TestCase
{
    public function testMultipleAckMarksAllUpToDeliveryTag(): void
    {
        $tracker = new PublishConfirmTracker();
        $tracker->register(1, 'm1', 1.0);
        $tracker->register(2, 'm2', 1.0);
        $tracker->register(3, 'm3', 1.0);

        $tracker->markAck(2, true);

        $this->assertTrue($tracker->get(1)['acked']);
        $this->assertTrue($tracker->get(2)['acked']);
        $this->assertFalse($tracker->get(3)['acked']);
    }

    public function testSingleNackMarksOnlyOne(): void
    {
        $tracker = new PublishConfirmTracker();
        $tracker->register(1, 'm1', 1.0);
        $tracker->register(2, 'm2', 1.0);

        $tracker->markNack(2, false);

        $this->assertFalse($tracker->get(1)['nacked']);
        $this->assertTrue($tracker->get(2)['nacked']);
    }

    public function testReturnCorrelationByMessageId(): void
    {
        $tracker = new PublishConfirmTracker();
        $tracker->register(10, 'msg-10', 1.0);

        $info = [
            'reply_code' => 312,
            'reply_text' => 'NO_ROUTE',
            'exchange' => 'ex',
            'routing_key' => 'rk',
        ];

        $seqNo = $tracker->markReturned('msg-10', $info);

        $this->assertSame(10, $seqNo);
        $this->assertTrue($tracker->get(10)['returned']);
        $this->assertSame($info, $tracker->get(10)['returnInfo']);
    }
}
