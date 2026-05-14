<?php

namespace illusiard\rabbitmq\message;

use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\ErrorCode;
use JsonException;

class JsonMessageSerializer implements MessageSerializerInterface
{
    public const CONTENT_TYPE = 'application/json';

    public int $decodeDepth = 512;

    public function __construct(array $config = [])
    {
        if (isset($config['decodeDepth']) && is_int($config['decodeDepth'])) {
            $this->decodeDepth = $config['decodeDepth'];
        }
    }

    /**
     * @param Envelope $env
     * @return string
     */
    public function encode(Envelope $env): string
    {
        try {
            return json_encode($env->toArray(), JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RabbitMqException('JSON encode failed: ' . $e->getMessage(), ErrorCode::SERIALIZATION_FAILED, 0, $e);
        }
    }

    /**
     * @param string $body
     * @param array $meta
     * @return Envelope
     */
    public function decode(string $body, array $meta = []): Envelope
    {
        try {
            $data = json_decode($body, true, $this->getDecodeDepth(), JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RabbitMqException(
                'JSON decode failed: ' . $e->getMessage(),
                ErrorCode::SERIALIZATION_FAILED,
                0,
                $e
            );
        }

        if (is_array($data) && $this->isEnvelopeShape($data)) {
            if (isset($data['message_id']) && !isset($data['messageId'])) {
                $data['messageId'] = $data['message_id'];
            }
            $env = Envelope::fromArray($data);
        } else {
            $properties = isset($meta['properties']) && is_array($meta['properties']) ? $meta['properties'] : [];
            $env = new Envelope(
                $data,
                [],
                [],
                null,
                isset($properties['correlation_id']) ? (string)$properties['correlation_id'] : null,
                isset($properties['message_id']) ? (string)$properties['message_id'] : null
            );
        }

        if (isset($meta['headers']) && is_array($meta['headers']) && empty($env->getHeaders())) {
            $env = new Envelope(
                $env->getPayload(),
                $meta['headers'],
                $env->getProperties(),
                $env->getType(),
                $env->getCorrelationId(),
                $env->getMessageId(),
                $env->getTimestamp()
            );
        }

        if (isset($meta['properties']) && is_array($meta['properties']) && empty($env->getProperties())) {
            $env = new Envelope(
                $env->getPayload(),
                $env->getHeaders(),
                $meta['properties'],
                $env->getType(),
                $env->getCorrelationId(),
                $env->getMessageId(),
                $env->getTimestamp()
            );
        }

        return $env;
    }

    private function isEnvelopeShape(array $data): bool
    {
        if (!array_key_exists('payload', $data)) {
            return false;
        }

        return array_key_exists('messageId', $data) || array_key_exists('message_id', $data);
    }

    public function canDecode(string $body, array $meta = []): bool
    {
        if (trim($body) === '') {
            return false;
        }

        try {
            json_decode($body, true, $this->getDecodeDepth(), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return true;
    }

    public function getContentType(): string
    {
        return self::CONTENT_TYPE;
    }

    private function getDecodeDepth(): int
    {
        return $this->decodeDepth > 0 ? $this->decodeDepth : 512;
    }
}
