<?php

namespace illusiard\rabbitmq\consume;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\retry\RetryHeader;
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
        $nextAttempt = $this->nextAttempt($attempts, $maxAttempts);

        if (empty($retryQueues)) {
            return $this->exhaust($context, $deadQueue, $policy['exhaustedAction'], $nextAttempt);
        }

        if ($maxAttempts > 0 && $attempts >= $maxAttempts) {
            return $this->exhaust($context, $deadQueue, $policy['exhaustedAction'], $nextAttempt);
        }

        if ($attempts < count($retryQueues)) {
            $next = $retryQueues[$attempts] ?? null;
            return $this->publishToQueue($context, $policy['retryExchange'], $next['name'], $nextAttempt);
        }

        return $this->exhaust($context, $deadQueue, $policy['exhaustedAction'], $nextAttempt);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function validate(): void
    {
        $this->normalizePolicy();
    }

    /**
     * @return array
     * @throws InvalidConfigException
     */
    private function normalizePolicy(): array
    {
        $managed = $this->options['managedRetry'] ?? null;
        $policy = [
            'enabled' => false,
            'maxAttempts' => null,
            'retryQueues' => [],
            'deadQueue' => null,
            'exhaustedAction' => 'reject',
            'retryExchange' => null,
            'deadExchange' => '',
        ];

        if (is_array($managed)) {
            return $this->finalizePolicy(array_merge($policy, $this->extractPolicy($managed), [
                'enabled' => (bool)($managed['enabled'] ?? false),
            ]));
        }

        if (is_bool($managed)) {
            $policy['enabled'] = $managed;
            $retryPolicy = $this->options['retryPolicy'] ?? [];
            if (!is_array($retryPolicy)) {
                throw new InvalidConfigException('retry policy must be an array.');
            }
            $policy = array_merge($policy, $this->extractPolicy($retryPolicy));

            return $this->finalizePolicy($policy);
        }

        if ($managed !== null) {
            throw new InvalidConfigException('managedRetry must be a boolean or an array.');
        }

        return $policy;
    }

    private function extractPolicy(array $source): array
    {
        return [
            'maxAttempts' => $source['maxAttempts'] ?? null,
            'retryQueues' => $source['retryQueues'] ?? [],
            'deadQueue' => $source['deadQueue'] ?? null,
            'exhaustedAction' => $source['exhaustedAction'] ?? 'reject',
            'retryExchange' => $source['retryExchange'] ?? null,
            'deadExchange' => $source['deadExchange'] ?? '',
        ];
    }

    /**
     * @param array $policy
     * @return array
     * @throws InvalidConfigException
     */
    private function finalizePolicy(array $policy): array
    {
        if ($policy['enabled']
            && $policy['retryExchange'] !== null
            && (!is_string($policy['retryExchange']) || $policy['retryExchange'] === '')
        ) {
            throw new InvalidConfigException('retry policy retryExchange must be a non-empty string.');
        }

        $policy['retryExchange'] = $this->service->resolveRetryExchange(
            is_string($policy['retryExchange']) ? $policy['retryExchange'] : null
        );

        if ($policy['enabled']) {
            $this->validateEnabledPolicy($policy);
        }

        return $policy;
    }

    /**
     * @param array $policy
     * @return void
     * @throws InvalidConfigException
     */
    private function validateEnabledPolicy(array $policy): void
    {
        if (!is_int($policy['maxAttempts']) || $policy['maxAttempts'] <= 0) {
            throw new InvalidConfigException('retry policy maxAttempts must be a positive integer.');
        }

        if (!in_array($policy['exhaustedAction'], ['reject', 'stop'], true)) {
            throw new InvalidConfigException('retry policy exhaustedAction must be reject or stop.');
        }

        if (!is_array($policy['retryQueues'])) {
            throw new InvalidConfigException('retry policy retryQueues must be an array.');
        }

        if ($policy['deadQueue'] !== null && (!is_string($policy['deadQueue']) || $policy['deadQueue'] === '')) {
            throw new InvalidConfigException('retry policy deadQueue must be a non-empty string or null.');
        }

        if (!is_string($policy['deadExchange'])) {
            throw new InvalidConfigException('retry policy deadExchange must be a string.');
        }

        foreach ($policy['retryQueues'] as $index => $queue) {
            if (!is_array($queue)) {
                throw new InvalidConfigException('retry policy retryQueues[' . $index . '] must be an array.');
            }
            if (!isset($queue['name']) || !is_string($queue['name']) || $queue['name'] === '') {
                throw new InvalidConfigException(
                    'retry policy retryQueues[' . $index . '].name must be a non-empty string.'
                );
            }
            if (!isset($queue['ttlMs']) || !is_int($queue['ttlMs']) || $queue['ttlMs'] <= 0) {
                throw new InvalidConfigException(
                    'retry policy retryQueues[' . $index . '].ttlMs must be a positive integer.'
                );
            }
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
            $policy = $this->normalizePolicy();
            $exchange = $policy['deadExchange'];
            return $this->publishToQueue($context, $exchange, $deadQueue, $attempt);
        }

        if ($exhaustedAction === 'stop') {
            return ConsumeResult::stop();
        }

        return ConsumeResult::reject();
    }

    /**
     * @param ConsumeContext $context
     * @param string $exchange
     * @param string $queue
     * @param int $attempt
     * @return ConsumeResult
     * @throws InvalidConfigException
     */
    private function publishToQueue(ConsumeContext $context, string $exchange, string $queue, int $attempt): ConsumeResult
    {
        $meta = $context->getMeta();
        $headers = $meta->getHeaders();
        $headers[RetryHeader::NAME] = RetryHeader::sanitize($attempt);

        $properties = $this->sanitizeRepublishProperties($meta->getProperties());
        if (isset($properties['application_headers'])) {
            unset($properties['application_headers']);
        }

        $body = $meta->getBody();
        if ($body === null) {
            $body = $this->service->getSerializer()->encode($context->getEnvelope());
        }

        $this->service->publish($body, $exchange, $queue, $properties, $headers);

        return ConsumeResult::ack();
    }

    private function sanitizeRepublishProperties(array $properties): array
    {
        unset(
            $properties['application_headers'],
            $properties['expiration'],
            $properties['reply_to']
        );

        return $properties;
    }

    private function resolveRetryCount(array $headers, int $maxAttempts): int
    {
        $max = $maxAttempts > 0 ? $maxAttempts : RetryHeader::MAX_RETRY_COUNT;

        return RetryHeader::sanitize($headers[RetryHeader::NAME] ?? null, $max);
    }

    private function nextAttempt(int $attempts, int $maxAttempts): int
    {
        return min($attempts + 1, $maxAttempts);
    }
}
