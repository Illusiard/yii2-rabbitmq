<?php

namespace illusiard\rabbitmq\definitions\consume;

class MessageMeta
{
    private array $headers;
    private array $properties;
    private ?string $body;
    private ?int $deliveryTag;
    private ?string $routingKey;
    private ?string $exchange;
    private bool $redelivered;

    public function __construct(
        array $headers,
        array $properties,
        ?string $body = null,
        ?int $deliveryTag = null,
        ?string $routingKey = null,
        ?string $exchange = null,
        bool $redelivered = false
    ) {
        $this->headers = $headers;
        $this->properties = $properties;
        $this->body = $body;
        $this->deliveryTag = $deliveryTag;
        $this->routingKey = $routingKey;
        $this->exchange = $exchange;
        $this->redelivered = $redelivered;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getDeliveryTag(): ?int
    {
        return $this->deliveryTag;
    }

    public function getRoutingKey(): ?string
    {
        return $this->routingKey;
    }

    public function getExchange(): ?string
    {
        return $this->exchange;
    }

    public function isRedelivered(): bool
    {
        return $this->redelivered;
    }
}
