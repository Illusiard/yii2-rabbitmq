<?php

namespace illusiard\rabbitmq\tests\fixtures;

class FakeRpcMessage
{
    private FakeRpcServerChannel $channel;

    public function __construct(FakeRpcServerChannel $channel)
    {
        $this->channel = $channel;
    }

    public function get_properties(): array
    {
        return [
            'reply_to' => 'reply.queue',
            'correlation_id' => 'corr-1',
        ];
    }

    public function getBody(): string
    {
        return json_encode([
            'messageId' => 'm1',
            'correlationId' => 'corr-1',
            'type' => 'rpc.request',
            'timestamp' => 1700000000,
            'payload' => ['ping' => true],
            'headers' => [],
            'properties' => [],
        ], JSON_THROW_ON_ERROR);
    }

    public function getChannel(): FakeRpcServerChannel
    {
        return $this->channel;
    }

    public function getDeliveryTag(): int
    {
        return 1;
    }
}
