<?php

namespace illusiard\rabbitmq\message;

use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\ErrorCode;

class JsonMessageSerializer implements MessageSerializerInterface
{
    private bool $throwOnError;

    public function __construct(array $config = [])
    {
        $this->throwOnError = $config['throwOnError'] ?? true;
    }

    public function encode(Envelope $env): string
    {
        if ($this->throwOnError) {
            try {
                return json_encode($env->toArray(), JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new RabbitMqException('JSON encode failed: ' . $e->getMessage(), ErrorCode::SERIALIZATION_FAILED, 0, $e);
            }
        }

        $json = json_encode($env->toArray());
        if ($json === false) {
            throw new RabbitMqException('JSON encode failed: ' . json_last_error_msg(), ErrorCode::SERIALIZATION_FAILED);
        }

        return $json;
    }

    public function decode(string $body, array $meta = []): Envelope
    {
        $data = null;

        if ($this->throwOnError) {
            try {
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new RabbitMqException('JSON decode failed: ' . $e->getMessage(), ErrorCode::SERIALIZATION_FAILED, 0, $e);
            }
        } else {
            $data = json_decode($body, true);
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new RabbitMqException('JSON decode failed: ' . json_last_error_msg(), ErrorCode::SERIALIZATION_FAILED);
            }
        }

        if (is_array($data) && $this->isEnvelopeShape($data)) {
            if (isset($data['message_id']) && !isset($data['messageId'])) {
                $data['messageId'] = $data['message_id'];
            }
            $env = Envelope::fromArray($data);
        } else {
            $env = new Envelope($data);
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
}
