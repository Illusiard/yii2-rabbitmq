<?php

namespace illusiard\rabbitmq\tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\exceptions\ErrorCode;
use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\message\JsonMessageSerializer;
use illusiard\rabbitmq\exceptions\RabbitMqException;
use yii\base\InvalidConfigException;

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

    public function testDecodeInvalidJsonDoesNotExposeBodyInException(): void
    {
        $serializer = new JsonMessageSerializer();
        $body = '{"password":"secret"';

        try {
            $serializer->decode($body);
            $this->fail('Decode should throw.');
        } catch (RabbitMqException $e) {
            $this->assertStringNotContainsString($body, $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testDecodePlainJsonObjectPreservesPayload(): void
    {
        $serializer = new JsonMessageSerializer();

        $payload = ['foo' => 'bar', 'count' => 2];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $decoded = $serializer->decode($body);

        $this->assertSame($payload, $decoded->getPayload());
    }

    public function testDecodePlainJsonUsesAmqpMessageId(): void
    {
        $serializer = new JsonMessageSerializer();

        $decoded = $serializer->decode('{"foo":"bar"}', [
            'properties' => [
                'message_id' => 'amqp-msg-1',
                'correlation_id' => 'corr-1',
            ],
        ]);

        $this->assertSame('amqp-msg-1', $decoded->getMessageId());
        $this->assertSame('corr-1', $decoded->getCorrelationId());
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testServiceDecodeEnvelopeInvalidJsonThrows(): void
    {
        $service = new RabbitMqService();

        $this->expectException(RabbitMqException::class);
        $service->decodeEnvelope('{invalid json}');
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testDecodePlainJsonArrayPreservesPayload(): void
    {
        $serializer = new JsonMessageSerializer();

        $payload = [1, 2, 3];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $decoded = $serializer->decode($body);

        $this->assertSame($payload, $decoded->getPayload());
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testDecodeEnvelopeJsonWithMessageId(): void
    {
        $serializer = new JsonMessageSerializer();

        $payload = ['a' => 1];
        $body = json_encode([
            'payload' => $payload,
            'messageId' => 'msg-1',
        ], JSON_THROW_ON_ERROR);

        $decoded = $serializer->decode($body);

        $this->assertSame('msg-1', $decoded->getMessageId());
        $this->assertSame($payload, $decoded->getPayload());
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testDecodeEnvelopeJsonWithMessageIdSnakeCase(): void
    {
        $serializer = new JsonMessageSerializer();

        $payload = ['b' => 2];
        $body = json_encode([
            'payload' => $payload,
            'message_id' => 'msg-2',
        ], JSON_THROW_ON_ERROR);

        $decoded = $serializer->decode($body);

        $this->assertSame('msg-2', $decoded->getMessageId());
        $this->assertSame($payload, $decoded->getPayload());
    }

    public function testCanDecodeEnvelopeJsonStructurally(): void
    {
        $serializer = new JsonMessageSerializer();

        $body = "{\n  \"payload\" : {\"a\": 1},\n  \"message_id\" : \"msg-3\"\n}";

        $this->assertTrue($serializer->canDecode($body));
    }

    public function testCanDecodePlainJsonPayload(): void
    {
        $serializer = new JsonMessageSerializer();

        $this->assertTrue($serializer->canDecode('{"a":1}'));
        $this->assertTrue($serializer->canDecode('null'));
        $this->assertFalse($serializer->canDecode('{invalid json}'));
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testDecodePayloadKeyWithoutIdKeepsWholeObject(): void
    {
        $serializer = new JsonMessageSerializer();

        $payload = [
            'payload' => ['c' => 3],
            'extra' => 'value',
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $decoded = $serializer->decode($body);

        $this->assertSame($payload, $decoded->getPayload());
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testDecodePlainJsonObjectWithoutPayloadKey(): void
    {
        $serializer = new JsonMessageSerializer();

        $payload = ['x' => 'y'];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $decoded = $serializer->decode($body);

        $this->assertSame($payload, $decoded->getPayload());
    }

    public function testDecodeInvalidJsonUsesSerializationFailedCode(): void
    {
        $serializer = new JsonMessageSerializer();

        try {
            $serializer->decode('{invalid json}');
            $this->fail('Decode should throw.');
        } catch (RabbitMqException $e) {
            $this->assertSame(ErrorCode::SERIALIZATION_FAILED, $e->getErrorCode());
        }
    }

    public function testEncodeInvalidPayloadThrowsRabbitMqException(): void
    {
        $serializer = new JsonMessageSerializer();

        try {
            $serializer->encode(new Envelope(INF));
            $this->fail('Encode should throw.');
        } catch (RabbitMqException $e) {
            $this->assertSame(ErrorCode::SERIALIZATION_FAILED, $e->getErrorCode());
        }
    }
}
