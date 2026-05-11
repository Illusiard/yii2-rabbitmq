<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\amqp\AmqpPublisher;
use PhpAmqpLib\Channel\AMQPChannel;

class TestAmqpPublisher extends AmqpPublisher
{
    public bool $waitCalled = false;

    protected function waitForPublish(
        AMQPChannel $channel,
        int $seqNo,
        string $messageId,
        ?string $correlationId,
        string $exchange,
        string $routingKey
    ): void {
        $this->waitCalled = true;
    }
}
