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

        if ($maxAttempts > 0 && $attempts >= $maxAttempts) {
            return RetryDecision::dead($deadQueue, 'max attempts reached');
        }

        if ($attempts < count($retryQueues)) {
            $next = $retryQueues[$attempts] ?? null;
            if (is_array($next) && isset($next['name']) && is_string($next['name']) && $next['name'] !== '') {
                return RetryDecision::retry($next['name'], 'retry attempt ' . ($attempts + 1));
            }
        }

        return RetryDecision::reject('no retry queue available');
    }

    private function resolveAttempts(array $meta): int
    {
        $headers = $meta['headers'] ?? [];
        if (is_array($headers)) {
            if (isset($headers['x-death']) && is_array($headers['x-death'])) {
                $count = 0;
                foreach ($headers['x-death'] as $entry) {
                    if (is_array($entry) && isset($entry['count'])) {
                        $count += (int)$entry['count'];
                    }
                }
                if ($count > 0) {
                    return $count;
                }
            }

            if (isset($headers['x-attempt'])) {
                return (int)$headers['x-attempt'];
            }
        }

        return 0;
    }
}
