<?php

namespace illusiard\rabbitmq\config;

use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\ErrorCode;
use illusiard\rabbitmq\helpers\ArrayHelper;

class ConfigValidator
{
    public function validate(array $config): void
    {
        if (!isset($config['amqp']) || !is_array($config['amqp'])) {
            throw new RabbitMqException('AMQP config is required.', ErrorCode::CONFIG_INVALID);
        }

        $this->validateAmqp($config['amqp'], 'amqp');

        if (isset($config['profiles'])) {
            if (!is_array($config['profiles'])) {
                throw new RabbitMqException('profiles must be an array.', ErrorCode::CONFIG_INVALID);
            }
            if (empty($config['profiles'])) {
                throw new RabbitMqException('profiles must not be empty.', ErrorCode::CONFIG_INVALID);
            }

            foreach ($config['profiles'] as $name => $profile) {
                if (!is_array($profile)) {
                    throw new RabbitMqException('profiles.' . $name . ' must be an array.', ErrorCode::CONFIG_INVALID);
                }
                $merged = array_merge($config['amqp'], $profile);
                $this->validateAmqp($merged, 'profiles.' . $name);
            }

            if (isset($config['defaultProfile']) && !is_string($config['defaultProfile'])) {
                throw new RabbitMqException('defaultProfile must be a string.', ErrorCode::CONFIG_INVALID);
            }
        }

        if (isset($config['topology'])) {
            if (!is_array($config['topology'])) {
                throw new RabbitMqException('topology must be an array.', ErrorCode::TOPOLOGY_INVALID);
            }
            $this->validateTopology($config['topology']);
        }

        $this->validateMiddlewares($config, 'publishMiddlewares');
        $this->validateMiddlewares($config, 'consumeMiddlewares');
        $this->validateConsumeOptions($config);
        $this->validateMessageLimits($config);
        $this->validateReturnHandler($config);
        $this->validateReturnSink($config);
    }

    public function validateTopology(array $config): void
    {
        $allowedExchangeTypes = ['direct', 'fanout', 'topic', 'headers'];

        if (isset($config['main']) && !is_array($config['main'])) {
            throw new RabbitMqException('topology.main must be an array.', ErrorCode::TOPOLOGY_INVALID);
        }
        if (isset($config['retry']) && !is_array($config['retry'])) {
            throw new RabbitMqException('topology.retry must be an array.', ErrorCode::TOPOLOGY_INVALID);
        }
        if (isset($config['dead']) && !is_array($config['dead'])) {
            throw new RabbitMqException('topology.dead must be an array.', ErrorCode::TOPOLOGY_INVALID);
        }

        $this->validateTopologyDefinitions($config, $allowedExchangeTypes);

        foreach ($config['main'] ?? [] as $index => $item) {
            if (!is_array($item)) {
                throw new RabbitMqException('topology.main[' . $index . '] must be an array.', ErrorCode::TOPOLOGY_INVALID);
            }
            $this->requireString($item, 'queue', 'topology.main[' . $index . '].queue', ErrorCode::TOPOLOGY_INVALID);
            $this->requireString($item, 'exchange', 'topology.main[' . $index . '].exchange', ErrorCode::TOPOLOGY_INVALID);
            $this->requireString($item, 'routingKey', 'topology.main[' . $index . '].routingKey', ErrorCode::TOPOLOGY_INVALID);

            $itemOptions = $item['options'] ?? [];
            if (isset($itemOptions['exchangeType']) && !in_array($itemOptions['exchangeType'], $allowedExchangeTypes, true)) {
                throw new RabbitMqException('topology.main[' . $index . '].options.exchangeType must be one of: ' . implode(', ', $allowedExchangeTypes), ErrorCode::TOPOLOGY_INVALID);
            }
            $this->validateBooleanOption($itemOptions, 'exchangeDurable', 'topology.main[' . $index . '].options.exchangeDurable', ErrorCode::TOPOLOGY_INVALID);
            $this->validateBooleanOption($itemOptions, 'exchangeAutoDelete', 'topology.main[' . $index . '].options.exchangeAutoDelete', ErrorCode::TOPOLOGY_INVALID);
            $this->validateBooleanOption($itemOptions, 'queueDurable', 'topology.main[' . $index . '].options.queueDurable', ErrorCode::TOPOLOGY_INVALID);
            $this->validateBooleanOption($itemOptions, 'queueExclusive', 'topology.main[' . $index . '].options.queueExclusive', ErrorCode::TOPOLOGY_INVALID);
            $this->validateBooleanOption($itemOptions, 'queueAutoDelete', 'topology.main[' . $index . '].options.queueAutoDelete', ErrorCode::TOPOLOGY_INVALID);

            if (isset($itemOptions['queueArguments']) && !is_array($itemOptions['queueArguments'])) {
                throw new RabbitMqException('topology.main[' . $index . '].options.queueArguments must be an array.', ErrorCode::TOPOLOGY_INVALID);
            }
            if (isset($itemOptions['deadLetterExchange']) && !is_string($itemOptions['deadLetterExchange'])) {
                throw new RabbitMqException('topology.main[' . $index . '].options.deadLetterExchange must be a string.', ErrorCode::TOPOLOGY_INVALID);
            }
            if (isset($itemOptions['deadLetterRoutingKey']) && !is_string($itemOptions['deadLetterRoutingKey'])) {
                throw new RabbitMqException('topology.main[' . $index . '].options.deadLetterRoutingKey must be a string.', ErrorCode::TOPOLOGY_INVALID);
            }
        }

        foreach ($config['retry'] ?? [] as $index => $item) {
            if (!is_array($item)) {
                throw new RabbitMqException('topology.retry[' . $index . '] must be an array.', ErrorCode::TOPOLOGY_INVALID);
            }
            $this->requireString($item, 'queue', 'topology.retry[' . $index . '].queue', ErrorCode::TOPOLOGY_INVALID);
            $this->requireInt($item, 'ttlMs', 'topology.retry[' . $index . '].ttlMs', ErrorCode::TOPOLOGY_INVALID);
            $this->requireString($item, 'deadLetterExchange', 'topology.retry[' . $index . '].deadLetterExchange', ErrorCode::TOPOLOGY_INVALID);
            $this->requireString($item, 'deadLetterRoutingKey', 'topology.retry[' . $index . '].deadLetterRoutingKey', ErrorCode::TOPOLOGY_INVALID);

            if ((int)$item['ttlMs'] <= 0) {
                throw new RabbitMqException('topology.retry[' . $index . '].ttlMs must be greater than 0.', ErrorCode::TOPOLOGY_INVALID);
            }
        }

        foreach ($config['dead'] ?? [] as $index => $item) {
            if (!is_array($item)) {
                throw new RabbitMqException('topology.dead[' . $index . '] must be an array.', ErrorCode::TOPOLOGY_INVALID);
            }
            $this->requireString($item, 'queue', 'topology.dead[' . $index . '].queue', ErrorCode::TOPOLOGY_INVALID);
        }

        $options = $config['options'] ?? [];
        if (isset($options['retryExchange']) && !is_string($options['retryExchange'])) {
            throw new RabbitMqException('topology.options.retryExchange must be a string.', ErrorCode::TOPOLOGY_INVALID);
        }
        if (isset($options['retryExchangeType']) && !in_array($options['retryExchangeType'], $allowedExchangeTypes, true)) {
            throw new RabbitMqException('topology.options.retryExchangeType must be one of: ' . implode(', ', $allowedExchangeTypes), ErrorCode::TOPOLOGY_INVALID);
        }
        $this->validateBooleanOption($options, 'retryExchangeDurable', 'topology.options.retryExchangeDurable', ErrorCode::TOPOLOGY_INVALID);
        $this->validateBooleanOption($options, 'strict', 'topology.options.strict', ErrorCode::TOPOLOGY_INVALID);
        $this->validateBooleanOption($options, 'dryRun', 'topology.options.dryRun', ErrorCode::TOPOLOGY_INVALID);
    }

    private function validateTopologyDefinitions(array $config, array $allowedExchangeTypes): void
    {
        foreach (['exchanges', 'exchange', 'queues', 'queue', 'bindings', 'binding'] as $key) {
            if (isset($config[$key]) && !is_array($config[$key])) {
                throw new RabbitMqException('topology.' . $key . ' must be an array.', ErrorCode::TOPOLOGY_INVALID);
            }
        }

        foreach ($this->normalizeTopologyItems($config, 'exchanges', 'exchange') as $index => $item) {
            if (!is_array($item)) {
                throw new RabbitMqException('topology.exchanges[' . $index . '] must be an array.', ErrorCode::TOPOLOGY_INVALID);
            }
            $this->requireString($item, 'name', 'topology.exchanges[' . $index . '].name', ErrorCode::TOPOLOGY_INVALID);
            if (isset($item['type']) && !in_array($item['type'], $allowedExchangeTypes, true)) {
                throw new RabbitMqException('topology.exchanges[' . $index . '].type must be one of: ' . implode(', ', $allowedExchangeTypes), ErrorCode::TOPOLOGY_INVALID);
            }
            $this->validateBooleanOption($item, 'durable', 'topology.exchanges[' . $index . '].durable', ErrorCode::TOPOLOGY_INVALID);
            $this->validateBooleanOption($item, 'autoDelete', 'topology.exchanges[' . $index . '].autoDelete', ErrorCode::TOPOLOGY_INVALID);
            $this->validateBooleanOption($item, 'internal', 'topology.exchanges[' . $index . '].internal', ErrorCode::TOPOLOGY_INVALID);
            if (isset($item['arguments']) && !is_array($item['arguments'])) {
                throw new RabbitMqException('topology.exchanges[' . $index . '].arguments must be an array.', ErrorCode::TOPOLOGY_INVALID);
            }
        }

        foreach ($this->normalizeTopologyItems($config, 'queues', 'queue') as $index => $item) {
            if (!is_array($item)) {
                throw new RabbitMqException('topology.queues[' . $index . '] must be an array.', ErrorCode::TOPOLOGY_INVALID);
            }
            $this->requireString($item, 'name', 'topology.queues[' . $index . '].name', ErrorCode::TOPOLOGY_INVALID);
            $this->validateBooleanOption($item, 'durable', 'topology.queues[' . $index . '].durable', ErrorCode::TOPOLOGY_INVALID);
            $this->validateBooleanOption($item, 'autoDelete', 'topology.queues[' . $index . '].autoDelete', ErrorCode::TOPOLOGY_INVALID);
            $this->validateBooleanOption($item, 'exclusive', 'topology.queues[' . $index . '].exclusive', ErrorCode::TOPOLOGY_INVALID);
            if (isset($item['arguments']) && !is_array($item['arguments'])) {
                throw new RabbitMqException('topology.queues[' . $index . '].arguments must be an array.', ErrorCode::TOPOLOGY_INVALID);
            }
        }

        foreach ($this->normalizeTopologyItems($config, 'bindings', 'binding') as $index => $item) {
            if (!is_array($item)) {
                throw new RabbitMqException('topology.bindings[' . $index . '] must be an array.', ErrorCode::TOPOLOGY_INVALID);
            }
            $this->requireString($item, 'exchange', 'topology.bindings[' . $index . '].exchange', ErrorCode::TOPOLOGY_INVALID);
            $this->requireString($item, 'queue', 'topology.bindings[' . $index . '].queue', ErrorCode::TOPOLOGY_INVALID);
            if (isset($item['routingKey']) && !is_string($item['routingKey'])) {
                throw new RabbitMqException('topology.bindings[' . $index . '].routingKey must be a string.', ErrorCode::TOPOLOGY_INVALID);
            }
            if (isset($item['arguments']) && !is_array($item['arguments'])) {
                throw new RabbitMqException('topology.bindings[' . $index . '].arguments must be an array.', ErrorCode::TOPOLOGY_INVALID);
            }
        }
    }

    private function normalizeTopologyItems(array $config, string $plural, string $singular): array
    {
        $items = $config[$plural] ?? $config[$singular] ?? [];
        if (!is_array($items)) {
            return [];
        }

        return ArrayHelper::normalizeItems($items);
    }

    private function validateAmqp(array $config, string $path): void
    {
        $this->requireString($config, 'host', $path . '.host', ErrorCode::CONFIG_INVALID);
        $this->requireInt($config, 'port', $path . '.port', ErrorCode::CONFIG_INVALID);
        $this->requireString($config, 'user', $path . '.user', ErrorCode::CONFIG_INVALID);
        $this->requireString($config, 'password', $path . '.password', ErrorCode::CONFIG_INVALID);

        if ($config['port'] < 1 || $config['port'] > 65535) {
            throw new RabbitMqException($path . '.port must be between 1 and 65535.', ErrorCode::CONFIG_INVALID);
        }

        if (isset($config['vhost']) && !is_string($config['vhost'])) {
            throw new RabbitMqException($path . '.vhost must be a string.', ErrorCode::CONFIG_INVALID);
        }

        $this->validateIntOption($config, 'heartbeat', $path . '.heartbeat', ErrorCode::CONFIG_INVALID);
        $this->validateIntOption($config, 'readWriteTimeout', $path . '.readWriteTimeout', ErrorCode::CONFIG_INVALID);
        $this->validateIntOption($config, 'connectionTimeout', $path . '.connectionTimeout', ErrorCode::CONFIG_INVALID);
        $this->validateIntOption($config, 'publishTimeout', $path . '.publishTimeout', ErrorCode::CONFIG_INVALID, 1);
        $this->validateIntOption($config, 'consumeReconnectAttempts', $path . '.consumeReconnectAttempts', ErrorCode::CONFIG_INVALID, 1);
        $this->validateIntOption($config, 'consumeReconnectDelaySeconds', $path . '.consumeReconnectDelaySeconds', ErrorCode::CONFIG_INVALID);
        $this->validateBooleanOption($config, 'confirm', $path . '.confirm', ErrorCode::CONFIG_INVALID);
        $this->validateBooleanOption($config, 'mandatory', $path . '.mandatory', ErrorCode::CONFIG_INVALID);
        $this->validateBooleanOption($config, 'mandatoryStrict', $path . '.mandatoryStrict', ErrorCode::CONFIG_INVALID);

        if (isset($config['ssl'])) {
            $this->validateSsl($config['ssl'], $path . '.ssl');
        }
    }

    private function validateMiddlewares(array $config, string $key): void
    {
        if (!isset($config[$key])) {
            return;
        }
        if (!is_array($config[$key])) {
            throw new RabbitMqException($key . ' must be an array.', ErrorCode::CONFIG_INVALID);
        }
        foreach ($config[$key] as $index => $item) {
            if (!is_string($item) && !is_array($item) && !is_object($item)) {
                throw new RabbitMqException($key . '[' . $index . '] must be string, array, or object.', ErrorCode::CONFIG_INVALID);
            }
        }
    }

    private function validateConsumeOptions(array $config): void
    {
        $this->validateBooleanOption($config, 'managedRetry', 'managedRetry', ErrorCode::CONFIG_INVALID);
        if (isset($config['retryPolicy']) && !is_array($config['retryPolicy'])) {
            throw new RabbitMqException('retryPolicy must be an array.', ErrorCode::CONFIG_INVALID);
        }

        if (($config['managedRetry'] ?? false) === true) {
            $this->validateRetryPolicy($config['retryPolicy'] ?? [], 'retryPolicy');
        }

        $this->validateBooleanOption($config, 'consumeFailFast', 'consumeFailFast', ErrorCode::CONFIG_INVALID);
        $this->validateClassList($config, 'fatalExceptionClasses', 'fatalExceptionClasses');
        $this->validateClassList($config, 'recoverableExceptionClasses', 'recoverableExceptionClasses');
    }

    private function validateMessageLimits(array $config): void
    {
        $this->validateIntOption($config, 'maxMessageBodyBytes', 'maxMessageBodyBytes', ErrorCode::CONFIG_INVALID);
        $this->validateIntOption($config, 'jsonDecodeDepth', 'jsonDecodeDepth', ErrorCode::CONFIG_INVALID, 1);

        if (isset($config['messageLimitExceededAction'])
            && !in_array($config['messageLimitExceededAction'], ['reject', 'retry', 'stop'], true)
        ) {
            throw new RabbitMqException(
                'messageLimitExceededAction must be reject, retry, or stop.',
                ErrorCode::CONFIG_INVALID
            );
        }
    }

    private function validateSsl($ssl, string $path): void
    {
        if ($ssl === null) {
            return;
        }

        if (!is_array($ssl)) {
            throw new RabbitMqException($path . ' must be an array or null.', ErrorCode::CONFIG_INVALID);
        }

        foreach ($ssl as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new RabbitMqException($path . ' keys must be non-empty strings.', ErrorCode::CONFIG_INVALID);
            }

            if (!is_string($value) && !is_int($value) && !is_bool($value) && !is_array($value) && $value !== null) {
                throw new RabbitMqException(
                    $path . '.' . $key . ' must be string, integer, boolean, array, or null.',
                    ErrorCode::CONFIG_INVALID
                );
            }
        }
    }

    private function validateRetryPolicy(array $policy, string $path): void
    {
        if (!isset($policy['maxAttempts']) || !is_int($policy['maxAttempts']) || $policy['maxAttempts'] <= 0) {
            throw new RabbitMqException($path . '.maxAttempts must be a positive integer.', ErrorCode::CONFIG_INVALID);
        }

        if (isset($policy['exhaustedAction'])
            && !in_array($policy['exhaustedAction'], ['reject', 'stop'], true)
        ) {
            throw new RabbitMqException($path . '.exhaustedAction must be reject or stop.', ErrorCode::CONFIG_INVALID);
        }

        if (isset($policy['retryExchange'])
            && (!is_string($policy['retryExchange']) || $policy['retryExchange'] === '')
        ) {
            throw new RabbitMqException($path . '.retryExchange must be a non-empty string.', ErrorCode::CONFIG_INVALID);
        }

        if (!isset($policy['retryQueues'])) {
            return;
        }

        if (!is_array($policy['retryQueues'])) {
            throw new RabbitMqException($path . '.retryQueues must be an array.', ErrorCode::CONFIG_INVALID);
        }

        foreach ($policy['retryQueues'] as $index => $queue) {
            if (!is_array($queue)) {
                throw new RabbitMqException(
                    $path . '.retryQueues[' . $index . '] must be an array.',
                    ErrorCode::CONFIG_INVALID
                );
            }
            if (!isset($queue['name']) || !is_string($queue['name']) || $queue['name'] === '') {
                throw new RabbitMqException(
                    $path . '.retryQueues[' . $index . '].name must be a non-empty string.',
                    ErrorCode::CONFIG_INVALID
                );
            }
            if (!isset($queue['ttlMs']) || !is_int($queue['ttlMs']) || $queue['ttlMs'] <= 0) {
                throw new RabbitMqException(
                    $path . '.retryQueues[' . $index . '].ttlMs must be a positive integer.',
                    ErrorCode::CONFIG_INVALID
                );
            }
        }
    }

    private function validateReturnHandler(array $config): void
    {
        $this->validateBooleanOption($config, 'returnHandlerEnabled', 'returnHandlerEnabled', ErrorCode::CONFIG_INVALID);

        if (!isset($config['returnHandler'])) {
            return;
        }

        if (!is_string($config['returnHandler']) && !is_array($config['returnHandler']) && !is_object($config['returnHandler'])) {
            throw new RabbitMqException('returnHandler must be string, array, object, or null.', ErrorCode::CONFIG_INVALID);
        }
    }

    private function validateReturnSink(array $config): void
    {
        $this->validateBooleanOption($config, 'returnSinkEnabled', 'returnSinkEnabled', ErrorCode::CONFIG_INVALID);

        if (!isset($config['returnSink'])) {
            return;
        }

        if (!is_string($config['returnSink']) && !is_array($config['returnSink']) && !is_object($config['returnSink'])) {
            throw new RabbitMqException('returnSink must be string, array, object, or null.', ErrorCode::CONFIG_INVALID);
        }
    }

    private function validateClassList(array $config, string $key, string $path): void
    {
        if (!isset($config[$key])) {
            return;
        }
        if (!is_array($config[$key])) {
            throw new RabbitMqException($path . ' must be an array.', ErrorCode::CONFIG_INVALID);
        }
        foreach ($config[$key] as $index => $item) {
            if (!is_string($item) || $item === '') {
                throw new RabbitMqException($path . '[' . $index . '] must be a non-empty string.', ErrorCode::CONFIG_INVALID);
            }
        }
    }

    private function requireString(array $item, string $key, string $path, string $errorCode): void
    {
        if (!isset($item[$key]) || !is_string($item[$key]) || $item[$key] === '') {
            throw new RabbitMqException($path . ' must be a non-empty string.', $errorCode);
        }
    }

    private function requireInt(array $item, string $key, string $path, string $errorCode): void
    {
        if (!isset($item[$key]) || !is_int($item[$key])) {
            throw new RabbitMqException($path . ' must be an integer.', $errorCode);
        }
    }

    private function validateIntOption(array $item, string $key, string $path, string $errorCode, int $min = 0): void
    {
        if (isset($item[$key])) {
            if (!is_int($item[$key])) {
                throw new RabbitMqException($path . ' must be an integer.', $errorCode);
            }
            if ($item[$key] < $min) {
                throw new RabbitMqException($path . ' must be >= ' . $min . '.', $errorCode);
            }
        }
    }

    private function validateBooleanOption(array $options, string $key, string $path, string $errorCode): void
    {
        if (isset($options[$key]) && !is_bool($options[$key])) {
            throw new RabbitMqException($path . ' must be a boolean.', $errorCode);
        }
    }
}
