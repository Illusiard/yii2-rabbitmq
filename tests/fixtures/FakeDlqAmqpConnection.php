<?php

namespace illusiard\rabbitmq\tests\fixtures;

class FakeDlqAmqpConnection
{
    private FakeDlqChannel $channel;

    public function __construct(FakeDlqChannel $channel)
    {
        $this->channel = $channel;
    }

    public function channel(): FakeDlqChannel
    {
        return $this->channel;
    }
}
