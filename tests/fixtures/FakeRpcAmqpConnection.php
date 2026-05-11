<?php

namespace illusiard\rabbitmq\tests\fixtures;

class FakeRpcAmqpConnection
{
    private FakeRpcChannelTimeout $channel;
    private FakeRpcConnection $connection;

    public function __construct(FakeRpcChannelTimeout $channel, FakeRpcConnection $connection)
    {
        $this->channel = $channel;
        $this->connection = $connection;
    }

    public function channel(): FakeRpcChannelTimeout
    {
        return $this->channel;
    }

    public function isConnected(): bool
    {
        return $this->connection->isConnected();
    }
}
