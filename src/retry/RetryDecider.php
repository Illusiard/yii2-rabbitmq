<?php

namespace illusiard\rabbitmq\retry;

use InvalidArgumentException;

class RetryDecider
{
    public function decide(array $meta, array $policy): RetryDecision
    {
        if (!isset($policy['maxAttempts']) || (int)$policy['maxAttempts'] <= 0) {
            throw new InvalidArgumentException('retry policy maxAttempts must be a positive integer.');
        }

        $maxAttempts = (int)$policy['maxAttempts'];
        $retryQueues = isset($policy['retryQueues']) && is_array($policy['retryQueues']) ? $policy['retryQueues'] : [];
        $deadQueue = $policy['deadQueue'] ?? null;
        $exhaustedAction = isset($policy['exhaustedAction']) ? (string)$policy['exhaustedAction'] : 'reject';
        if (!in_array($exhaustedAction, ['reject', 'stop'], true)) {
            throw new InvalidArgumentException('retry policy exhaustedAction must be reject or stop.');
        }

        $attempts = $this->resolveAttempts($meta);

        if (count($retryQueues) === 0) {
            return $this->exhausted($deadQueue, $exhaustedAction, 'no-retry-queues');
        }

        if ($attempts >= $maxAttempts) {
            return $this->exhausted($deadQueue, $exhaustedAction, 'max-attempts-reached');
        }

        if ($attempts < count($retryQueues)) {
            $next = $retryQueues[$attempts] ?? null;
            if (is_array($next) && isset($next['name']) && is_string($next['name']) && $next['name'] !== '') {
                return RetryDecision::retry($next['name'], 'retry-attempt-' . ($attempts + 1));
            }
        }

        return $this->exhausted($deadQueue, $exhaustedAction, 'no-retry-queues');
    }

    private function exhausted($deadQueue, string $exhaustedAction, string $reason): RetryDecision
    {
        if (is_string($deadQueue) && $deadQueue !== '') {
            return RetryDecision::dead($deadQueue, $reason);
        }

        if ($exhaustedAction === 'stop') {
            return RetryDecision::stop($reason);
        }

        return RetryDecision::reject($reason);
    }

    private function resolveAttempts(array $meta): int
    {
        $headers = $meta['headers'] ?? [];
        if (!is_array($headers)) {
            return 0;
        }

        return RetryHeader::sanitize($headers[RetryHeader::NAME] ?? null);
    }
}
