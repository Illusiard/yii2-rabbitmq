<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\message\MessageSerializerInterface;

class CustomContentTypeSerializer implements MessageSerializerInterface
{
    public function encode(Envelope $env): string
    {
        return (string)$env->getPayload();
    }

    public function decode(string $body, array $meta = []): Envelope
    {
        return new Envelope($body);
    }

    public function canDecode(string $body, array $meta = []): bool
    {
        return $body !== '';
    }

    public function getContentType(): string
    {
        return 'text/plain';
    }
}
