<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\message\Envelope;

class EnvelopeTest extends TestCase
{
    public function testGeneratesMessageId(): void
    {
        $env = new Envelope(['a' => 1]);
        $this->assertNotEmpty($env->getMessageId());
    }

    public function testToArrayFromArray(): void
    {
        $env = new Envelope(
            ['foo' => 'bar'],
            ['x' => 'y'],
            ['content_type' => 'application/json'],
            'type.a',
            'corr-1',
            'msg-1',
            1700000000
        );

        $data = $env->toArray();
        $copy = Envelope::fromArray($data);

        $this->assertSame($data['messageId'], $copy->getMessageId());
        $this->assertSame($data['correlationId'], $copy->getCorrelationId());
        $this->assertSame($data['type'], $copy->getType());
        $this->assertSame($data['timestamp'], $copy->getTimestamp());
        $this->assertSame($data['payload'], $copy->getPayload());
        $this->assertSame($data['headers'], $copy->getHeaders());
        $this->assertSame($data['properties'], $copy->getProperties());
    }
}
