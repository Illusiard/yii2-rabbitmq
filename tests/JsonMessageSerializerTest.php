<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\message\JsonMessageSerializer;
use illusiard\rabbitmq\exceptions\RabbitMqException;

class JsonMessageSerializerTest extends TestCase
{
    public function testEncodeDecode(): void
    {
        $serializer = new JsonMessageSerializer();
        $env = new Envelope(['a' => 1], ['h' => 'v'], ['content_type' => 'application/json'], 'type.a', 'corr-1', 'msg-1', 1700000000);

        $body = $serializer->encode($env);
        $decoded = $serializer->decode($body);

        $this->assertSame('msg-1', $decoded->getMessageId());
        $this->assertSame('corr-1', $decoded->getCorrelationId());
        $this->assertSame('type.a', $decoded->getType());
        $this->assertSame(1700000000, $decoded->getTimestamp());
        $this->assertSame(['a' => 1], $decoded->getPayload());
        $this->assertSame(['h' => 'v'], $decoded->getHeaders());
    }

    public function testDecodeInvalidJsonThrows(): void
    {
        $serializer = new JsonMessageSerializer();

        $this->expectException(RabbitMqException::class);
        $serializer->decode('{invalid json}');
    }
}
