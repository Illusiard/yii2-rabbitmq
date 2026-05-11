<?php

namespace illusiard\rabbitmq\tests\fixtures;

class HealthcheckChannel
{
    private HealthcheckFakeConnectionWithChannel $connection;

    public function __construct(HealthcheckFakeConnectionWithChannel $connection)
    {
        $this->connection = $connection;
    }

    public function close(): void
    {
        $this->connection->channelCloses++;
    }
}
