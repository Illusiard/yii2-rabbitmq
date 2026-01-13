<?php

namespace illusiard\rabbitmq\config;

use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\ErrorCode;

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
        $this->validateBooleanOption($config, 'confirm', $path . '.confirm', ErrorCode::CONFIG_INVALID);
        $this->validateBooleanOption($config, 'mandatory', $path . '.mandatory', ErrorCode::CONFIG_INVALID);
        $this->validateBooleanOption($config, 'mandatoryStrict', $path . '.mandatoryStrict', ErrorCode::CONFIG_INVALID);
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
        $this->validateBooleanOption($config, 'consumeFailFast', 'consumeFailFast', ErrorCode::CONFIG_INVALID);
        $this->validateClassList($config, 'fatalExceptionClasses', 'fatalExceptionClasses');
        $this->validateClassList($config, 'recoverableExceptionClasses', 'recoverableExceptionClasses');
    }

    private function validateReturnHandler(array $config): void
    {
        $this->validateBooleanOption($config, 'returnHandlerEnabled', 'returnHandlerEnabled', ErrorCode::CONFIG_INVALID);

        if (!isset($config['returnHandler'])) {
            return;
        }

        if ($config['returnHandler'] === null) {
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

        if ($config['returnSink'] === null) {
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
