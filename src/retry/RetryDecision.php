<?php

namespace illusiard\rabbitmq\retry;

class RetryDecision
{
    public string $action;
    public ?string $retryQueue;
    public string $reason;

    private function __construct(string $action, ?string $retryQueue, string $reason)
    {
        $this->action = $action;
        $this->retryQueue = $retryQueue;
        $this->reason = $reason;
    }

    public static function ack(string $reason = ''): self
    {
        return new self('ack', null, $reason);
    }

    public static function reject(string $reason = ''): self
    {
        return new self('reject', null, $reason);
    }

    public static function retry(string $retryQueue, string $reason = ''): self
    {
        return new self('retry', $retryQueue, $reason);
    }

    public static function dead(?string $deadQueue, string $reason = ''): self
    {
        return new self('dead', $deadQueue, $reason);
    }

    public static function stop(string $reason = ''): self
    {
        return new self('stop', null, $reason);
    }
}
