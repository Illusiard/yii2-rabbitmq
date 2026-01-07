<?php

namespace illusiard\rabbitmq\amqp;

use PhpAmqpLib\Wire\AMQPTable;
use Yii;
use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\ErrorCode;
use illusiard\rabbitmq\config\ConfigValidator;

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
            throw new RabbitMqException('Topology config must be an array.', ErrorCode::TOPOLOGY_INVALID);
        }

        $validator = new ConfigValidator();
        $validator->validateTopology($config);
    }
}
