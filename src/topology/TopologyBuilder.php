<?php

namespace illusiard\rabbitmq\topology;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\exceptions\ErrorCode;
use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\helpers\ArrayHelper;
use JsonException;
use ReflectionException;
use Throwable;
use yii\base\InvalidConfigException;

class TopologyBuilder
{
    /**
     * @param array $config
     * @return Topology
     * @throws JsonException
     */
    public function buildFromConfig(array $config): Topology
    {
        $topology = new Topology();

        $this->applyConfig($topology, $config);

        return $topology;
    }

    /**
     * @param RabbitMqService $service
     * @return Topology
     * @throws InvalidConfigException
     * @throws JsonException
     * @throws ReflectionException
     */
    public function buildFromService(RabbitMqService $service): Topology
    {
        $topology = new Topology();

        $config = is_array($service->topology ?? null) ? $service->topology : [];
        $this->applyConfig($topology, $config);
        $this->validateConsumerQueues($topology, $service);

        return $topology;
    }

    /**
     * @param Topology $topology
     * @param RabbitMqService $service
     * @return void
     * @throws ReflectionException
     * @throws InvalidConfigException
     */
    private function validateConsumerQueues(Topology $topology, RabbitMqService $service): void
    {
        try {
            $registry = $service->getConsumerRegistry();
        } catch (Throwable) {
            return;
        }

        foreach ($registry->all() as $fqcn) {
            $consumer = $service->createConsumerDefinition($fqcn);
            $queue = $consumer->getQueue();
            if (!$topology->hasQueue($queue)) {
                throw new RabbitMqException(
                    "Consumer queue '$queue' is missing from topology.",
                    ErrorCode::TOPOLOGY_INVALID
                );
            }
        }
    }

    /**
     * @param Topology $topology
     * @param array $config
     * @return void
     * @throws JsonException
     */
    private function applyConfig(Topology $topology, array $config): void
    {
        $exchanges = $config['exchanges'] ?? $config['exchange'] ?? [];
        $queues = $config['queues'] ?? $config['queue'] ?? [];
        $bindings = $config['bindings'] ?? $config['binding'] ?? [];

        foreach (ArrayHelper::normalizeItems($exchanges) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = isset($item['name']) ? (string)$item['name'] : '';
            if ($name === '') {
                continue;
            }
            $topology->addExchange(new ExchangeDefinition(
                $name,
                isset($item['type']) ? (string)$item['type'] : 'direct',
                !isset($item['durable']) || $item['durable'],
                isset($item['autoDelete']) && $item['autoDelete'],
                isset($item['internal']) && $item['internal'],
                isset($item['arguments']) && is_array($item['arguments']) ? $item['arguments'] : []
            ));
        }

        foreach (ArrayHelper::normalizeItems($queues) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = isset($item['name']) ? (string)$item['name'] : '';
            if ($name === '') {
                continue;
            }
            $topology->addQueue(new QueueDefinition(
                $name,
                !isset($item['durable']) || $item['durable'],
                isset($item['autoDelete']) && $item['autoDelete'],
                isset($item['exclusive']) && $item['exclusive'],
                isset($item['arguments']) && is_array($item['arguments']) ? $item['arguments'] : []
            ));
        }

        foreach (ArrayHelper::normalizeItems($bindings) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $exchange = isset($item['exchange']) ? (string)$item['exchange'] : '';
            $queue = isset($item['queue']) ? (string)$item['queue'] : '';
            if ($exchange === '' || $queue === '') {
                continue;
            }
            $topology->addBinding(new BindingDefinition(
                $exchange,
                $queue,
                isset($item['routingKey']) ? (string)$item['routingKey'] : '',
                isset($item['arguments']) && is_array($item['arguments']) ? $item['arguments'] : []
            ));
        }

        $this->applyLegacyConfig($topology, $config);
    }

    /**
     * @param Topology $topology
     * @param array $config
     * @return void
     * @throws JsonException
     */
    private function applyLegacyConfig(Topology $topology, array $config): void
    {
        $options = isset($config['options']) && is_array($config['options']) ? $config['options'] : [];

        $retryExchange = isset($options['retryExchange']) ? (string)$options['retryExchange'] : 'retry-exchange';
        $retryExchangeType = isset($options['retryExchangeType']) ? (string)$options['retryExchangeType'] : 'direct';
        $retryExchangeDurable = !isset($options['retryExchangeDurable']) || $options['retryExchangeDurable'];

        foreach (ArrayHelper::normalizeItems($config['main'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $queue = isset($item['queue']) ? (string)$item['queue'] : '';
            $exchange = isset($item['exchange']) ? (string)$item['exchange'] : '';
            $routingKey = isset($item['routingKey']) ? (string)$item['routingKey'] : '';
            if ($queue === '' || $exchange === '' || $routingKey === '') {
                continue;
            }

            $itemOptions = isset($item['options']) && is_array($item['options']) ? $item['options'] : [];
            $exchangeType = isset($itemOptions['exchangeType']) ? (string)$itemOptions['exchangeType'] : 'direct';
            $exchangeDurable = !isset($itemOptions['exchangeDurable']) || $itemOptions['exchangeDurable'];
            $exchangeAutoDelete = isset($itemOptions['exchangeAutoDelete']) && $itemOptions['exchangeAutoDelete'];

            $deadLetterExchange = $itemOptions['deadLetterExchange'] ?? $itemOptions['retryExchange'] ?? $retryExchange;
            if (!is_string($deadLetterExchange)) {
                $deadLetterExchange = $retryExchange;
            }
            $deadLetterRoutingKey = $itemOptions['deadLetterRoutingKey'] ?? $queue;
            if (!is_string($deadLetterRoutingKey)) {
                $deadLetterRoutingKey = $queue;
            }

            $queueArgs = isset($itemOptions['queueArguments']) && is_array($itemOptions['queueArguments'])
                ? $itemOptions['queueArguments']
                : [];
            if ($deadLetterExchange !== '') {
                $queueArgs['x-dead-letter-exchange'] = $deadLetterExchange;
                $queueArgs['x-dead-letter-routing-key'] = $deadLetterRoutingKey;
            }

            $topology->addExchange(new ExchangeDefinition(
                $exchange,
                $exchangeType,
                $exchangeDurable,
                $exchangeAutoDelete,
                false,
                []
            ));

            if ($deadLetterExchange !== '') {
                $topology->addExchange(new ExchangeDefinition(
                    $deadLetterExchange,
                    isset($itemOptions['retryExchangeType']) ? (string)$itemOptions['retryExchangeType'] : $retryExchangeType,
                    isset($itemOptions['retryExchangeDurable']) ? (bool)$itemOptions['retryExchangeDurable'] : $retryExchangeDurable,
                    false,
                    false,
                    []
                ));
            }

            $topology->addQueue(new QueueDefinition(
                $queue,
                !isset($itemOptions['queueDurable']) || $itemOptions['queueDurable'],
                isset($itemOptions['queueAutoDelete']) && $itemOptions['queueAutoDelete'],
                isset($itemOptions['queueExclusive']) && $itemOptions['queueExclusive'],
                $queueArgs
            ));

            $topology->addBinding(new BindingDefinition($exchange, $queue, $routingKey));
        }

        foreach (ArrayHelper::normalizeItems($config['retry'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $queue = isset($item['queue']) ? (string)$item['queue'] : '';
            $ttlMs = $item['ttlMs'] ?? null;
            $deadLetterExchange = isset($item['deadLetterExchange']) ? (string)$item['deadLetterExchange'] : '';
            $deadLetterRoutingKey = isset($item['deadLetterRoutingKey']) ? (string)$item['deadLetterRoutingKey'] : '';
            if ($queue === '' || !is_int($ttlMs) || $ttlMs <= 0 || $deadLetterExchange === '') {
                continue;
            }

            $topology->addExchange(new ExchangeDefinition(
                $retryExchange,
                $retryExchangeType,
                $retryExchangeDurable,
                false,
                false,
                []
            ));

            $topology->addQueue(new QueueDefinition(
                $queue,
                true,
                false,
                false,
                [
                    'x-message-ttl' => $ttlMs,
                    'x-dead-letter-exchange' => $deadLetterExchange,
                    'x-dead-letter-routing-key' => $deadLetterRoutingKey !== '' ? $deadLetterRoutingKey : $queue,
                ]
            ));

            $topology->addBinding(new BindingDefinition($retryExchange, $queue, $queue));
        }

        foreach (ArrayHelper::normalizeItems($config['dead'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $queue = isset($item['queue']) ? (string)$item['queue'] : '';
            if ($queue === '') {
                continue;
            }

            $topology->addQueue(new QueueDefinition($queue, true, false, false, []));
        }
    }
}
