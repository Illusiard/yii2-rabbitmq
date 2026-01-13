<?php

namespace illusiard\rabbitmq\consume;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;

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
        $attempts = $this->resolveRetryCount($meta->getHeaders());
        $maxAttempts = $policy['maxAttempts'];
        $retryQueues = $policy['retryQueues'];
        $deadQueue = $policy['deadQueue'];

        if (empty($retryQueues)) {
            return $deadQueue ? $this->publishToQueue($context, $deadQueue, $attempts + 1) : ConsumeResult::reject(false);
        }

        if ($maxAttempts > 0 && $attempts >= $maxAttempts) {
            return $deadQueue ? $this->publishToQueue($context, $deadQueue, $attempts + 1) : ConsumeResult::reject(false);
        }

        if ($attempts < count($retryQueues)) {
            $next = $retryQueues[$attempts] ?? null;
            if (is_array($next) && isset($next['name']) && is_string($next['name']) && $next['name'] !== '') {
                return $this->publishToQueue($context, $next['name'], $attempts + 1);
            }
        }

        return $deadQueue ? $this->publishToQueue($context, $deadQueue, $attempts + 1) : ConsumeResult::reject(false);
    }

    private function normalizePolicy(): array
    {
        $managed = $this->options['managedRetry'] ?? null;
        $policy = [
            'enabled' => false,
            'maxAttempts' => 0,
            'retryQueues' => [],
            'deadQueue' => null,
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
            }
        }

        return $policy;
    }

    private function publishToQueue(ConsumeContext $context, string $queue, int $attempt): ConsumeResult
    {
        $meta = $context->getMeta();
        $headers = $meta->getHeaders();
        $headers['x-retry-count'] = $attempt;

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

    private function resolveRetryCount(array $headers): int
    {
        if (isset($headers['x-retry-count']) && is_int($headers['x-retry-count']) && $headers['x-retry-count'] >= 0) {
            return $headers['x-retry-count'];
        }

        return 0;
    }
}
