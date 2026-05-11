<?php

namespace illusiard\rabbitmq\message;

interface MessageSerializerInterface
{
    public function encode(Envelope $env): string;

    public function decode(string $body, array $meta = []): Envelope;

    public function canDecode(string $body, array $meta = []): bool;

    public function getContentType(): string;
}
