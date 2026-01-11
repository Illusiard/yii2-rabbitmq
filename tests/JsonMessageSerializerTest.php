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

    public function testDecodePlainJsonObjectPreservesPayload(): void
    {
        $serializer = new JsonMessageSerializer();

        $payload = ['foo' => 'bar', 'count' => 2];
        $body = json_encode($payload);

        $decoded = $serializer->decode($body);

        $this->assertSame($payload, $decoded->getPayload());
    }

    public function testDecodePlainJsonArrayPreservesPayload(): void
    {
        $serializer = new JsonMessageSerializer();

        $payload = [1, 2, 3];
        $body = json_encode($payload);

        $decoded = $serializer->decode($body);

        $this->assertSame($payload, $decoded->getPayload());
    }

    public function testDecodeEnvelopeJsonWithMessageId(): void
    {
        $serializer = new JsonMessageSerializer();

        $payload = ['a' => 1];
        $body = json_encode([
            'payload' => $payload,
            'messageId' => 'msg-1',
        ]);

        $decoded = $serializer->decode($body);

        $this->assertSame('msg-1', $decoded->getMessageId());
        $this->assertSame($payload, $decoded->getPayload());
    }

    public function testDecodeEnvelopeJsonWithMessageIdSnakeCase(): void
    {
        $serializer = new JsonMessageSerializer();

        $payload = ['b' => 2];
        $body = json_encode([
            'payload' => $payload,
            'message_id' => 'msg-2',
        ]);

        $decoded = $serializer->decode($body);

        $this->assertSame('msg-2', $decoded->getMessageId());
        $this->assertSame($payload, $decoded->getPayload());
    }

    public function testDecodePayloadKeyWithoutIdKeepsWholeObject(): void
    {
        $serializer = new JsonMessageSerializer();

        $payload = [
            'payload' => ['c' => 3],
            'extra' => 'value',
        ];
        $body = json_encode($payload);

        $decoded = $serializer->decode($body);

        $this->assertSame($payload, $decoded->getPayload());
    }

    public function testDecodePlainJsonObjectWithoutPayloadKey(): void
    {
        $serializer = new JsonMessageSerializer();

        $payload = ['x' => 'y'];
        $body = json_encode($payload);

        $decoded = $serializer->decode($body);

        $this->assertSame($payload, $decoded->getPayload());
    }
}
