<?php

namespace illusiard\rabbitmq\tests\fixtures;

class FakeDlqChannel
{
    public int $acks = 0;
    public int $rejects = 0;
    public bool $lastRejectRequeue = false;
    public int $purges = 0;
    public string $lastPurgeQueue = '';
    private array $messages;

    public function __construct(array $messages)
    {
        $this->messages = $messages;
    }

    public function setMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    public function attachSelfToMessages(): void
    {
        foreach ($this->messages as $message) {
            if (method_exists($message, 'setChannel')) {
                $message->setChannel($this);
            }
        }
    }

    public function basic_get(string $queue, bool $noAck)
    {
        return array_shift($this->messages);
    }

    public function basic_ack($deliveryTag): void
    {
        $this->acks++;
    }

    public function basic_reject($deliveryTag, $requeue): void
    {
        $this->rejects++;
        $this->lastRejectRequeue = (bool)$requeue;
    }

    public function queue_purge(string $queue): void
    {
        $this->purges++;
        $this->lastPurgeQueue = $queue;
    }

    public function is_open(): bool
    {
        return true;
    }

    public function close(): void
    {
    }
}
