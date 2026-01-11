<?php

namespace illusiard\rabbitmq\amqp;

class ReturnedMessage
{
    public ?string $messageId;
    public ?string $correlationId;
    public string $exchange;
    public string $routingKey;
    public int $replyCode;
    public string $replyText;
    public array $headers;
    public array $properties;
    public int $bodySize;
    public float $occurredAt;

    public function __construct(
        ?string $messageId,
        ?string $correlationId,
        string $exchange,
        string $routingKey,
        int $replyCode,
        string $replyText,
        array $headers,
        array $properties,
        int $bodySize,
        float $occurredAt
    ) {
        $this->messageId = $messageId;
        $this->correlationId = $correlationId;
        $this->exchange = $exchange;
        $this->routingKey = $routingKey;
        $this->replyCode = $replyCode;
        $this->replyText = $replyText;
        $this->headers = $headers;
        $this->properties = $properties;
        $this->bodySize = $bodySize;
        $this->occurredAt = $occurredAt;
    }
}
