<?php

namespace illusiard\rabbitmq\retry;

class RetryHeader
{
    public const NAME = 'x-retry-count';
    public const MAX_RETRY_COUNT = 1000000;

    public static function sanitize($value, int $max = self::MAX_RETRY_COUNT): int
    {
        if (!is_int($value) || $value < 0) {
            return 0;
        }

        $max = $max > 0 ? $max : self::MAX_RETRY_COUNT;

        return min($value, $max);
    }
}
