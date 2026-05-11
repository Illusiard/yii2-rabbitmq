<?php

namespace illusiard\rabbitmq\consume;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\retry\RetryHeader;

class ManagedRetryPolicy implements RetryPolicyInterface
{
    private RabbitMqService $service;
    private array $options;

    public function __construct(RabbitMqService $service, array $options)
    {
        $this->service = $service;
        $this->options = $options;
    }

    public function apply(ConsumeResult $result, ConsumeContext $context): ConsumeResult
    {
        if ($result->getAction() !== ConsumeResult::ACTION_RETRY) {
            return $result;
        }

        $policy = $this->normalizePolicy();
        if (!$policy['enabled']) {
            return ConsumeResult::reject(false);
        }

        $meta = $context->getMeta();
        $maxAttempts = $policy['maxAttempts'];
        $retryQueues = $policy['retryQueues'];
        $deadQueue = $policy['deadQueue'];
        $attempts = $this->resolveRetryCount($meta->getHeaders(), $maxAttempts);

        if (empty($retryQueues)) {
            return $this->exhaust($context, $deadQueue, $policy['exhaustedAction'], $attempts + 1);
        }

        if ($maxAttempts > 0 && $attempts >= $maxAttempts) {
            return $this->exhaust($context, $deadQueue, $policy['exhaustedAction'], $attempts + 1);
        }

        if ($attempts < count($retryQueues)) {
            $next = $retryQueues[$attempts] ?? null;
            if (is_array($next) && isset($next['name']) && is_string($next['name']) && $next['name'] !== '') {
                return $this->publishToQueue($context, $next['name'], $attempts + 1);
            }
        }

        return $this->exhaust($context, $deadQueue, $policy['exhaustedAction'], $attempts + 1);
    }

    private function normalizePolicy(): array
    {
        $managed = $this->options['managedRetry'] ?? null;
        $policy = [
            'enabled' => false,
            'maxAttempts' => 0,
            'retryQueues' => [],
            'deadQueue' => null,
            'exhaustedAction' => 'reject',
        ];

        if (is_array($managed)) {
            $policy['enabled'] = (bool)($managed['enabled'] ?? false);
            $policy['maxAttempts'] = isset($managed['maxAttempts']) ? (int)$managed['maxAttempts'] : 0;
            $policy['retryQueues'] = isset($managed['retryQueues']) && is_array($managed['retryQueues'])
                ? $managed['retryQueues']
                : [];
            $policy['deadQueue'] = isset($managed['deadQueue']) && is_string($managed['deadQueue'])
                ? $managed['deadQueue']
                : null;
            $policy['exhaustedAction'] = isset($managed['exhaustedAction']) && is_string($managed['exhaustedAction'])
                ? $managed['exhaustedAction']
                : 'reject';
            if ($policy['enabled']) {
                $this->validateEnabledPolicy($policy);
            }
            return $policy;
        }

        if (is_bool($managed)) {
            $policy['enabled'] = $managed;
            $retryPolicy = $this->options['retryPolicy'] ?? [];
            if (is_array($retryPolicy)) {
                $policy['maxAttempts'] = isset($retryPolicy['maxAttempts']) ? (int)$retryPolicy['maxAttempts'] : 0;
                $policy['retryQueues'] = isset($retryPolicy['retryQueues']) && is_array($retryPolicy['retryQueues'])
                    ? $retryPolicy['retryQueues']
                    : [];
                $policy['deadQueue'] = isset($retryPolicy['deadQueue']) && is_string($retryPolicy['deadQueue'])
                    ? $retryPolicy['deadQueue']
                    : null;
                $policy['exhaustedAction'] = isset($retryPolicy['exhaustedAction']) && is_string($retryPolicy['exhaustedAction'])
                    ? $retryPolicy['exhaustedAction']
                    : 'reject';
            }
            if ($policy['enabled']) {
                $this->validateEnabledPolicy($policy);
            }
        }

        return $policy;
    }

    private function validateEnabledPolicy(array $policy): void
    {
        if ((int)$policy['maxAttempts'] <= 0) {
            throw new \InvalidArgumentException('retry policy maxAttempts must be a positive integer.');
        }

        if (!in_array($policy['exhaustedAction'], ['reject', 'stop'], true)) {
            throw new \InvalidArgumentException('retry policy exhaustedAction must be reject or stop.');
        }
    }

    private function exhaust(ConsumeContext $context, ?string $deadQueue, string $exhaustedAction, int $attempt): ConsumeResult
    {
        if ($deadQueue !== null && $deadQueue !== '') {
            return $this->publishToQueue($context, $deadQueue, $attempt);
        }

        if ($exhaustedAction === 'stop') {
            return ConsumeResult::stop();
        }

        return ConsumeResult::reject(false);
    }

    private function publishToQueue(ConsumeContext $context, string $queue, int $attempt): ConsumeResult
    {
        $meta = $context->getMeta();
        $headers = $meta->getHeaders();
        $headers[RetryHeader::NAME] = RetryHeader::sanitize($attempt);

        $properties = $meta->getProperties();
        if (isset($properties['application_headers'])) {
            unset($properties['application_headers']);
        }

        $body = $meta->getBody();
        if ($body === null) {
            $body = $this->service->getSerializer()->encode($context->getEnvelope());
        }

        $this->service->publish($body, '', $queue, $properties, $headers);

        return ConsumeResult::ack();
    }

    private function resolveRetryCount(array $headers, int $maxAttempts): int
    {
        $max = $maxAttempts > 0 ? $maxAttempts : RetryHeader::MAX_RETRY_COUNT;

        return RetryHeader::sanitize($headers[RetryHeader::NAME] ?? null, $max);
    }
}
