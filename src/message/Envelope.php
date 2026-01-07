<?php

namespace illusiard\rabbitmq\message;

class Envelope
{
    private string $messageId;
    private ?string $correlationId;
    private ?string $type;
    private int $timestamp;
    private $payload;
    private array $headers;
    private array $properties;

    public function __construct(
        $payload,
        array $headers = [],
        array $properties = [],
        ?string $type = null,
        ?string $correlationId = null,
        ?string $messageId = null,
        ?int $timestamp = null
    ) {
        $this->payload = $payload;
        $this->headers = $headers;
        $this->properties = $properties;
        $this->type = $type;
        $this->correlationId = $correlationId;
        $this->messageId = $messageId ?: self::generateMessageId();
        $this->timestamp = $timestamp ?? time();
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getPayload()
    {
        return $this->payload;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function withHeader(string $name, $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;
        return $this->cloneWith($this->payload, $headers, $this->properties, $this->type, $this->correlationId, $this->messageId, $this->timestamp);
    }

    public function withHeaders(array $headers): self
    {
        return $this->cloneWith($this->payload, array_merge($this->headers, $headers), $this->properties, $this->type, $this->correlationId, $this->messageId, $this->timestamp);
    }

    public function withProperty(string $name, $value): self
    {
        $properties = $this->properties;
        $properties[$name] = $value;
        return $this->cloneWith($this->payload, $this->headers, $properties, $this->type, $this->correlationId, $this->messageId, $this->timestamp);
    }

    public function withProperties(array $properties): self
    {
        return $this->cloneWith($this->payload, $this->headers, array_merge($this->properties, $properties), $this->type, $this->correlationId, $this->messageId, $this->timestamp);
    }

    public function withCorrelationId(?string $correlationId): self
    {
        return $this->cloneWith($this->payload, $this->headers, $this->properties, $this->type, $correlationId, $this->messageId, $this->timestamp);
    }

    public function withType(?string $type): self
    {
        return $this->cloneWith($this->payload, $this->headers, $this->properties, $type, $this->correlationId, $this->messageId, $this->timestamp);
    }

    private function cloneWith(
        $payload,
        array $headers,
        array $properties,
        ?string $type,
        ?string $correlationId,
        string $messageId,
        int $timestamp
    ): self {
        return new self($payload, $headers, $properties, $type, $correlationId, $messageId, $timestamp);
    }

    public function toArray(): array
    {
        return [
            'messageId' => $this->messageId,
            'correlationId' => $this->correlationId,
            'type' => $this->type,
            'timestamp' => $this->timestamp,
            'payload' => $this->payload,
            'headers' => $this->headers,
            'properties' => $this->properties,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['payload'] ?? null,
            isset($data['headers']) && is_array($data['headers']) ? $data['headers'] : [],
            isset($data['properties']) && is_array($data['properties']) ? $data['properties'] : [],
            $data['type'] ?? null,
            $data['correlationId'] ?? null,
            $data['messageId'] ?? null,
            isset($data['timestamp']) ? (int)$data['timestamp'] : null
        );
    }

    private static function generateMessageId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return uniqid('msg_', true);
        }
    }
}
