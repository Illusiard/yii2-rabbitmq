<?php

namespace illusiard\rabbitmq\tests\fixtures;

class FakeRpcServerChannel
{
    public int $acks = 0;
    private bool $consuming = true;

    public function basic_consume($queue, $tag, $noLocal, $noAck, $exclusive, $noWait, $callback): void
    {
        $message = new FakeRpcMessage($this);
        $callback($message);
        $this->consuming = false;
    }

    public function is_consuming(): bool
    {
        return $this->consuming;
    }

    public function wait(): void
    {
    }

    public function basic_ack($deliveryTag): void
    {
        $this->acks++;
    }

    public function basic_reject($deliveryTag, $requeue): void
    {
    }

    public function is_open(): bool
    {
        return true;
    }

    public function close(): void
    {
    }
}
