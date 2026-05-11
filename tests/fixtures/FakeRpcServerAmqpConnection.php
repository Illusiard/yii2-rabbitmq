<?php

namespace illusiard\rabbitmq\tests\fixtures;

class FakeRpcServerAmqpConnection
{
    private FakeRpcServerChannel $channel;
    private FakeRpcServerConnection $connection;

    public function __construct(FakeRpcServerChannel $channel, FakeRpcServerConnection $connection)
    {
        $this->channel = $channel;
        $this->connection = $connection;
    }

    public function channel(): FakeRpcServerChannel
    {
        return $this->channel;
    }

    public function isConnected(): bool
    {
        return $this->connection->isConnected();
    }
}
