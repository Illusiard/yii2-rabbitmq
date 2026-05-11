<?php

namespace illusiard\rabbitmq\retry;

class RetryDecider
{
    public function decide(array $meta, array $policy): RetryDecision
    {
        $maxAttempts = isset($policy['maxAttempts']) ? (int)$policy['maxAttempts'] : 0;
        $retryQueues = isset($policy['retryQueues']) && is_array($policy['retryQueues']) ? $policy['retryQueues'] : [];
        $deadQueue = $policy['deadQueue'] ?? null;

        $attempts = $this->resolveAttempts($meta);

        if (count($retryQueues) === 0) {
            return RetryDecision::reject('no-retry-queues');
        }

        if ($maxAttempts > 0 && $attempts >= $maxAttempts) {
            return $deadQueue ? RetryDecision::dead($deadQueue, 'max-attempts-reached') : RetryDecision::reject('max-attempts-reached');
        }

        if ($attempts < count($retryQueues)) {
            $next = $retryQueues[$attempts] ?? null;
            if (is_array($next) && isset($next['name']) && is_string($next['name']) && $next['name'] !== '') {
                return RetryDecision::retry($next['name'], 'retry-attempt-' . ($attempts + 1));
            }
        }

        return $deadQueue ? RetryDecision::dead($deadQueue, 'no-retry-queues') : RetryDecision::reject('no-retry-queues');
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
