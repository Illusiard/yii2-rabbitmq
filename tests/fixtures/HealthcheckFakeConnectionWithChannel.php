<?php

namespace illusiard\rabbitmq\tests\fixtures;

class HealthcheckFakeConnectionWithChannel extends HealthcheckFakeConnection
{
    public int $channelCloses = 0;

    public function getAmqpConnection(): object
    {
        return new HealthcheckAmqpConnection($this);
    }
}
