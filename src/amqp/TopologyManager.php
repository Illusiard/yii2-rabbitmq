<?php

namespace illusiard\rabbitmq\amqp;

use PhpAmqpLib\Wire\AMQPTable;
use Yii;
use illusiard\rabbitmq\exceptions\RabbitMqException;

class TopologyManager
{
    private AmqpConnection $connection;
    private string $retryExchange;
    private string $retryExchangeType;
    private bool $retryExchangeDurable;
    private bool $strict;
    private bool $dryRun;

    public function __construct(AmqpConnection $connection, array $options = [])
    {
        $this->connection = $connection;
        $this->retryExchange = $options['retryExchange'] ?? 'retry-exchange';
        $this->retryExchangeType = $options['retryExchangeType'] ?? 'direct';
        $this->retryExchangeDurable = $options['retryExchangeDurable'] ?? true;
        $this->strict = (bool)($options['strict'] ?? false);
        $this->dryRun = (bool)($options['dryRun'] ?? false);
    }

    public function declareMainQueue(string $queue, string $exchange, string $routingKey, array $options = []): void
    {
        $channel = $this->connection->getAmqpConnection()->channel();

        try {
            $exchangeType = $options['exchangeType'] ?? 'direct';
            $exchangeDurable = $options['exchangeDurable'] ?? true;
            $exchangeAutoDelete = $options['exchangeAutoDelete'] ?? false;

            $retryExchange = $options['deadLetterExchange'] ?? $options['retryExchange'] ?? $this->retryExchange;
            $retryExchangeType = $options['retryExchangeType'] ?? $this->retryExchangeType;
            $retryExchangeDurable = $options['retryExchangeDurable'] ?? $this->retryExchangeDurable;

            $channel->exchange_declare($exchange, $exchangeType, false, $exchangeDurable, $exchangeAutoDelete);
            $channel->exchange_declare($retryExchange, $retryExchangeType, false, $retryExchangeDurable, false);

            $arguments = $options['queueArguments'] ?? [];
            $arguments['x-dead-letter-exchange'] = $retryExchange;
            $arguments['x-dead-letter-routing-key'] = $options['deadLetterRoutingKey'] ?? $queue;

            $queueDurable = $options['queueDurable'] ?? true;
            $queueExclusive = $options['queueExclusive'] ?? false;
            $queueAutoDelete = $options['queueAutoDelete'] ?? false;

            $channel->queue_declare(
                $queue,
                false,
                $queueDurable,
                $queueExclusive,
                $queueAutoDelete,
                false,
                new AMQPTable($arguments)
            );

            $channel->queue_bind($queue, $exchange, $routingKey);
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }

    public function declareRetryQueue(
        string $queue,
        int $ttlMs,
        string $deadLetterExchange,
        string $deadLetterRoutingKey
    ): void {
        $channel = $this->connection->getAmqpConnection()->channel();

        try {
            $channel->exchange_declare(
                $this->retryExchange,
                $this->retryExchangeType,
                false,
                $this->retryExchangeDurable,
                false
            );

            $arguments = [
                'x-message-ttl' => $ttlMs,
                'x-dead-letter-exchange' => $deadLetterExchange,
                'x-dead-letter-routing-key' => $deadLetterRoutingKey,
            ];

            $channel->queue_declare(
                $queue,
                false,
                true,
                false,
                false,
                false,
                new AMQPTable($arguments)
            );

            $channel->queue_bind($queue, $this->retryExchange, $queue);
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }

    public function declareDeadQueue(string $queue): void
    {
        $channel = $this->connection->getAmqpConnection()->channel();

        try {
            $channel->queue_declare($queue, false, true, false, false);
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }

    public function plan(array $config): array
    {
        $plan = [];
        $options = $config['options'] ?? [];

        $retryExchange = $options['retryExchange'] ?? $this->retryExchange;
        $retryExchangeType = $options['retryExchangeType'] ?? $this->retryExchangeType;
        $retryExchangeDurable = $options['retryExchangeDurable'] ?? $this->retryExchangeDurable;

        foreach ($config['main'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $queue = $item['queue'] ?? null;
            $exchange = $item['exchange'] ?? null;
            $routingKey = $item['routingKey'] ?? null;
            if (!is_string($queue) || $queue === '' || !is_string($exchange) || $exchange === '' || !is_string($routingKey)) {
                continue;
            }

            $itemOptions = $item['options'] ?? [];
            $exchangeType = $itemOptions['exchangeType'] ?? 'direct';
            $exchangeDurable = $itemOptions['exchangeDurable'] ?? true;
            $exchangeAutoDelete = $itemOptions['exchangeAutoDelete'] ?? false;

            $itemRetryExchange = $itemOptions['deadLetterExchange'] ?? $itemOptions['retryExchange'] ?? $retryExchange;
            $itemRetryExchangeType = $itemOptions['retryExchangeType'] ?? $retryExchangeType;
            $itemRetryExchangeDurable = $itemOptions['retryExchangeDurable'] ?? $retryExchangeDurable;

            $plan[] = [
                'action' => 'exchange_declare',
                'name' => $exchange,
                'type' => $exchangeType,
                'durable' => (bool)$exchangeDurable,
                'auto_delete' => (bool)$exchangeAutoDelete,
            ];
            $plan[] = [
                'action' => 'exchange_declare',
                'name' => $itemRetryExchange,
                'type' => $itemRetryExchangeType,
                'durable' => (bool)$itemRetryExchangeDurable,
                'auto_delete' => false,
            ];

            $arguments = $itemOptions['queueArguments'] ?? [];
            $arguments['x-dead-letter-exchange'] = $itemRetryExchange;
            $arguments['x-dead-letter-routing-key'] = $itemOptions['deadLetterRoutingKey'] ?? $queue;

            $plan[] = [
                'action' => 'queue_declare',
                'name' => $queue,
                'durable' => (bool)($itemOptions['queueDurable'] ?? true),
                'exclusive' => (bool)($itemOptions['queueExclusive'] ?? false),
                'auto_delete' => (bool)($itemOptions['queueAutoDelete'] ?? false),
                'arguments' => $arguments,
            ];
            $plan[] = [
                'action' => 'queue_bind',
                'queue' => $queue,
                'exchange' => $exchange,
                'routing_key' => $routingKey,
            ];
        }

        foreach ($config['retry'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $queue = $item['queue'] ?? null;
            $ttlMs = $item['ttlMs'] ?? null;
            $deadLetterExchange = $item['deadLetterExchange'] ?? null;
            $deadLetterRoutingKey = $item['deadLetterRoutingKey'] ?? null;

            if (!is_string($queue) || $queue === '' || !is_int($ttlMs) || $ttlMs <= 0
                || !is_string($deadLetterExchange) || $deadLetterExchange === ''
                || !is_string($deadLetterRoutingKey)
            ) {
                continue;
            }

            $plan[] = [
                'action' => 'exchange_declare',
                'name' => $retryExchange,
                'type' => $retryExchangeType,
                'durable' => (bool)$retryExchangeDurable,
                'auto_delete' => false,
            ];

            $plan[] = [
                'action' => 'queue_declare',
                'name' => $queue,
                'durable' => true,
                'exclusive' => false,
                'auto_delete' => false,
                'arguments' => [
                    'x-message-ttl' => $ttlMs,
                    'x-dead-letter-exchange' => $deadLetterExchange,
                    'x-dead-letter-routing-key' => $deadLetterRoutingKey,
                ],
            ];

            $plan[] = [
                'action' => 'queue_bind',
                'queue' => $queue,
                'exchange' => $retryExchange,
                'routing_key' => $queue,
            ];
        }

        foreach ($config['dead'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $queue = $item['queue'] ?? null;
            if (!is_string($queue) || $queue === '') {
                continue;
            }

            $plan[] = [
                'action' => 'queue_declare',
                'name' => $queue,
                'durable' => true,
                'exclusive' => false,
                'auto_delete' => false,
                'arguments' => [],
            ];
        }

        return $plan;
    }

    public function apply(array $config, array $options = []): void
    {
        $strict = $options['strict'] ?? $this->strict;
        $dryRun = $options['dryRun'] ?? $this->dryRun;

        if ($strict) {
            $this->validateConfig($config);
        }

        $plan = $this->plan($config);

        if ($dryRun) {
            foreach ($plan as $step) {
                Yii::info('Topology plan: ' . json_encode($step), 'rabbitmq');
            }
            return;
        }

        $channel = $this->connection->getAmqpConnection()->channel();

        try {
            foreach ($plan as $step) {
                switch ($step['action']) {
                    case 'exchange_declare':
                        $channel->exchange_declare(
                            $step['name'],
                            $step['type'],
                            false,
                            $step['durable'],
                            $step['auto_delete']
                        );
                        break;
                    case 'queue_declare':
                        $arguments = $step['arguments'] ?? [];
                        $channel->queue_declare(
                            $step['name'],
                            false,
                            $step['durable'],
                            $step['exclusive'],
                            $step['auto_delete'],
                            false,
                            new AMQPTable($arguments)
                        );
                        break;
                    case 'queue_bind':
                        $channel->queue_bind(
                            $step['queue'],
                            $step['exchange'],
                            $step['routing_key']
                        );
                        break;
                }
            }
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }

    private function validateConfig(array $config): void
    {
        if (!is_array($config)) {
            throw new RabbitMqException('Topology config must be an array.');
        }

        if (isset($config['main']) && !is_array($config['main'])) {
            throw new RabbitMqException('Topology config "main" must be an array.');
        }
        if (isset($config['retry']) && !is_array($config['retry'])) {
            throw new RabbitMqException('Topology config "retry" must be an array.');
        }
        if (isset($config['dead']) && !is_array($config['dead'])) {
            throw new RabbitMqException('Topology config "dead" must be an array.');
        }

        $allowedExchangeTypes = ['direct', 'fanout', 'topic', 'headers'];

        foreach ($config['main'] ?? [] as $index => $item) {
            if (!is_array($item)) {
                throw new RabbitMqException('Topology main[' . $index . '] must be an array.');
            }
            $this->requireString($item, 'queue', 'Topology main[' . $index . '].queue');
            $this->requireString($item, 'exchange', 'Topology main[' . $index . '].exchange');
            $this->requireString($item, 'routingKey', 'Topology main[' . $index . '].routingKey');

            $itemOptions = $item['options'] ?? [];
            if (isset($itemOptions['exchangeType']) && !in_array($itemOptions['exchangeType'], $allowedExchangeTypes, true)) {
                throw new RabbitMqException('Topology main[' . $index . '].options.exchangeType must be one of: ' . implode(', ', $allowedExchangeTypes));
            }
            $this->validateBooleanOption($itemOptions, 'exchangeDurable', 'Topology main[' . $index . '].options.exchangeDurable');
            $this->validateBooleanOption($itemOptions, 'exchangeAutoDelete', 'Topology main[' . $index . '].options.exchangeAutoDelete');
            $this->validateBooleanOption($itemOptions, 'queueDurable', 'Topology main[' . $index . '].options.queueDurable');
            $this->validateBooleanOption($itemOptions, 'queueExclusive', 'Topology main[' . $index . '].options.queueExclusive');
            $this->validateBooleanOption($itemOptions, 'queueAutoDelete', 'Topology main[' . $index . '].options.queueAutoDelete');

            if (isset($itemOptions['queueArguments']) && !is_array($itemOptions['queueArguments'])) {
                throw new RabbitMqException('Topology main[' . $index . '].options.queueArguments must be an array.');
            }
            if (isset($itemOptions['deadLetterExchange']) && !is_string($itemOptions['deadLetterExchange'])) {
                throw new RabbitMqException('Topology main[' . $index . '].options.deadLetterExchange must be a string.');
            }
            if (isset($itemOptions['deadLetterRoutingKey']) && !is_string($itemOptions['deadLetterRoutingKey'])) {
                throw new RabbitMqException('Topology main[' . $index . '].options.deadLetterRoutingKey must be a string.');
            }
        }

        foreach ($config['retry'] ?? [] as $index => $item) {
            if (!is_array($item)) {
                throw new RabbitMqException('Topology retry[' . $index . '] must be an array.');
            }
            $this->requireString($item, 'queue', 'Topology retry[' . $index . '].queue');
            $this->requireInt($item, 'ttlMs', 'Topology retry[' . $index . '].ttlMs');
            $this->requireString($item, 'deadLetterExchange', 'Topology retry[' . $index . '].deadLetterExchange');
            $this->requireString($item, 'deadLetterRoutingKey', 'Topology retry[' . $index . '].deadLetterRoutingKey');

            if ((int)$item['ttlMs'] <= 0) {
                throw new RabbitMqException('Topology retry[' . $index . '].ttlMs must be greater than 0.');
            }
        }

        foreach ($config['dead'] ?? [] as $index => $item) {
            if (!is_array($item)) {
                throw new RabbitMqException('Topology dead[' . $index . '] must be an array.');
            }
            $this->requireString($item, 'queue', 'Topology dead[' . $index . '].queue');
        }

        $options = $config['options'] ?? [];
        if (isset($options['retryExchange']) && !is_string($options['retryExchange'])) {
            throw new RabbitMqException('Topology options.retryExchange must be a string.');
        }
        if (isset($options['retryExchangeType']) && !in_array($options['retryExchangeType'], $allowedExchangeTypes, true)) {
            throw new RabbitMqException('Topology options.retryExchangeType must be one of: ' . implode(', ', $allowedExchangeTypes));
        }
        $this->validateBooleanOption($options, 'retryExchangeDurable', 'Topology options.retryExchangeDurable');
        $this->validateBooleanOption($options, 'strict', 'Topology options.strict');
        $this->validateBooleanOption($options, 'dryRun', 'Topology options.dryRun');
    }

    private function requireString(array $item, string $key, string $path): void
    {
        if (!isset($item[$key]) || !is_string($item[$key]) || $item[$key] === '') {
            throw new RabbitMqException($path . ' must be a non-empty string.');
        }
    }

    private function requireInt(array $item, string $key, string $path): void
    {
        if (!isset($item[$key]) || !is_int($item[$key])) {
            throw new RabbitMqException($path . ' must be an integer.');
        }
    }

    private function validateBooleanOption(array $options, string $key, string $path): void
    {
        if (isset($options[$key]) && !is_bool($options[$key])) {
            throw new RabbitMqException($path . ' must be a boolean.');
        }
    }
}
