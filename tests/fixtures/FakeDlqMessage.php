<?php

namespace illusiard\rabbitmq\tests\fixtures;

use PhpAmqpLib\Wire\AMQPTable;

class FakeDlqMessage
{
    private string $messageId;
    private string $correlationId;
    private ?FakeDlqChannel $channel;

    public function __construct(string $messageId, string $correlationId, ?FakeDlqChannel $channel)
    {
        $this->messageId = $messageId;
        $this->correlationId = $correlationId;
        $this->channel = $channel;
    }

    public function setChannel(FakeDlqChannel $channel): void
    {
        $this->channel = $channel;
    }

    public function get_properties(): array
    {
        return [
            'message_id' => $this->messageId,
            'correlation_id' => $this->correlationId,
            'application_headers' => new AMQPTable(['x-death' => [['count' => 1]]]),
        ];
    }

    public function getBody(): string
    {
        return 'body-' . $this->messageId;
    }

    public function getRoutingKey(): string
    {
        return 'rk';
    }

    public function getExchange(): string
    {
        return 'ex';
    }

    public function getRedelivered(): bool
    {
        return false;
    }

    public function getChannel(): ?FakeDlqChannel
    {
        return $this->channel;
    }

    public function getDeliveryTag(): int
    {
        return 1;
    }
}
