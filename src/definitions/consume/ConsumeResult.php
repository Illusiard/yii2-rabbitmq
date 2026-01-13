<?php

namespace illusiard\rabbitmq\definitions\consume;

use InvalidArgumentException;

class ConsumeResult
{
    public const ACTION_ACK = 'ack';
    public const ACTION_RETRY = 'retry';
    public const ACTION_REJECT = 'reject';
    public const ACTION_REQUEUE = 'requeue';
    public const ACTION_STOP = 'stop';

    private string $action;
    private bool $requeue;

    private function __construct(string $action, bool $requeue = false)
    {
        $this->action = $action;
        $this->requeue = $requeue;
    }

    public static function ack(): self
    {
        return new self(self::ACTION_ACK);
    }

    public static function retry(): self
    {
        return new self(self::ACTION_RETRY);
    }

    public static function reject(bool $requeue = false): self
    {
        return new self(self::ACTION_REJECT, $requeue);
    }

    public static function requeue(): self
    {
        return new self(self::ACTION_REQUEUE, true);
    }

    public static function stop(): self
    {
        return new self(self::ACTION_STOP);
    }

    public static function fromLegacyBool(bool $value): self
    {
        return $value ? self::ack() : self::retry();
    }

    public static function normalizeHandlerResult($result): self
    {
        if ($result instanceof self) {
            return $result;
        }

        if (is_bool($result)) {
            return self::fromLegacyBool($result);
        }

        throw new InvalidArgumentException('Handler result must be ConsumeResult or bool.');
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function shouldRequeue(): bool
    {
        return $this->requeue;
    }
}
