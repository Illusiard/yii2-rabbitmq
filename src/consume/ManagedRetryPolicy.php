<?php

namespace illusiard\rabbitmq\consume;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\retry\RetryHeader;
use InvalidArgumentException;
use yii\base\InvalidConfigException;

class ManagedRetryPolicy implements RetryPolicyInterface
{
    private RabbitMqService $service;
    private array $options;

    public function __construct(RabbitMqService $service, array $options)
    {
        $this->service = $service;
        $this->options = $options;
    }

    /**
     * @param ConsumeResult $result
     * @param ConsumeContext $context
     * @return ConsumeResult
     * @throws InvalidConfigException
     */
    public function apply(ConsumeResult $result, ConsumeContext $context): ConsumeResult
    {
        if ($result->getAction() !== ConsumeResult::ACTION_RETRY) {
            return $result;
        }

        $policy = $this->normalizePolicy();
        if (!$policy['enabled']) {
            return ConsumeResult::reject();
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
            return $this->finalizePolicy(array_merge($policy, $this->extractPolicy($managed), [
                'enabled' => (bool)($managed['enabled'] ?? false),
            ]));
        }

        if (is_bool($managed)) {
            $policy['enabled'] = $managed;
            $retryPolicy = $this->options['retryPolicy'] ?? [];
            if (is_array($retryPolicy)) {
                $policy = array_merge($policy, $this->extractPolicy($retryPolicy));
            }

            return $this->finalizePolicy($policy);
        }

        return $policy;
    }

    private function extractPolicy(array $source): array
    {
        return [
            'maxAttempts' => isset($source['maxAttempts']) ? (int)$source['maxAttempts'] : 0,
            'retryQueues' => isset($source['retryQueues']) && is_array($source['retryQueues'])
                ? $source['retryQueues']
                : [],
            'deadQueue' => isset($source['deadQueue']) && is_string($source['deadQueue'])
                ? $source['deadQueue']
                : null,
            'exhaustedAction' => isset($source['exhaustedAction']) && is_string($source['exhaustedAction'])
                ? $source['exhaustedAction']
                : 'reject',
        ];
    }

    private function finalizePolicy(array $policy): array
    {
        if ($policy['enabled']) {
            $this->validateEnabledPolicy($policy);
        }

        return $policy;
    }

    private function validateEnabledPolicy(array $policy): void
    {
        if ((int)$policy['maxAttempts'] <= 0) {
            throw new InvalidArgumentException('retry policy maxAttempts must be a positive integer.');
        }

        if (!in_array($policy['exhaustedAction'], ['reject', 'stop'], true)) {
            throw new InvalidArgumentException('retry policy exhaustedAction must be reject or stop.');
        }
    }

    /**
     * @param ConsumeContext $context
     * @param string|null $deadQueue
     * @param string $exhaustedAction
     * @param int $attempt
     * @return ConsumeResult
     * @throws InvalidConfigException
     */
    private function exhaust(ConsumeContext $context, ?string $deadQueue, string $exhaustedAction, int $attempt): ConsumeResult
    {
        if ($deadQueue !== null && $deadQueue !== '') {
            return $this->publishToQueue($context, $deadQueue, $attempt);
        }

        if ($exhaustedAction === 'stop') {
            return ConsumeResult::stop();
        }

        return ConsumeResult::reject();
    }

    /**
     * @param ConsumeContext $context
     * @param string $queue
     * @param int $attempt
     * @return ConsumeResult
     * @throws InvalidConfigException
     */
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
