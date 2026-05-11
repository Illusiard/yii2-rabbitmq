<?php

namespace illusiard\rabbitmq\tests\fixtures;

use PhpAmqpLib\Exception\AMQPTimeoutException;

class FakeRpcChannelTimeout
{
    public function queue_declare($queue, $passive, $durable, $exclusive, $autoDelete): array
    {
        return ['amq.gen-1', 0, 0];
    }

    public function basic_consume($queue, $tag, $noLocal, $noAck, $exclusive, $noWait, $callback): string
    {
        return 'ctag-1';
    }

    public function wait($allowed = null, $nonBlocking = false, $timeout = 0): void
    {
        throw new AMQPTimeoutException('timeout');
    }

    public function is_open(): bool
    {
        return true;
    }

    public function basic_cancel($tag): void
    {
    }

    public function close(): void
    {
    }
}
