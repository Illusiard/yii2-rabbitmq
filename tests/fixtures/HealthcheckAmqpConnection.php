<?php

namespace illusiard\rabbitmq\tests\fixtures;

class HealthcheckAmqpConnection
{
    private HealthcheckFakeConnectionWithChannel $connection;

    public function __construct(HealthcheckFakeConnectionWithChannel $connection)
    {
        $this->connection = $connection;
    }

    public function channel(): object
    {
        return new HealthcheckChannel($this->connection);
    }
}
