<?php

namespace illusiard\rabbitmq\topology;

use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\ErrorCode;

class Topology
{
    /** @var ExchangeDefinition[] */
    private array $exchanges = [];
    /** @var QueueDefinition[] */
    private array $queues = [];
    /** @var BindingDefinition[] */
    private array $bindings = [];
    private array $bindingKeys = [];

    public function addExchange(ExchangeDefinition $exchange): void
    {
        $name = $exchange->getName();
        if (isset($this->exchanges[$name])) {
            $existing = $this->exchanges[$name];
            if (!$this->isSameExchange($existing, $exchange)) {
                throw new RabbitMqException("Duplicate exchange definition '{$name}'.", ErrorCode::TOPOLOGY_INVALID);
            }
            return;
        }
        $this->exchanges[$exchange->getName()] = $exchange;
    }

    public function addQueue(QueueDefinition $queue): void
    {
        $name = $queue->getName();
        if (isset($this->queues[$name])) {
            $existing = $this->queues[$name];
            if (!$this->isSameQueue($existing, $queue)) {
                throw new RabbitMqException("Duplicate queue definition '{$name}'.", ErrorCode::TOPOLOGY_INVALID);
            }
            return;
        }
        $this->queues[$queue->getName()] = $queue;
    }

    public function addBinding(BindingDefinition $binding): void
    {
        $key = $this->bindingKey($binding);
        if (isset($this->bindingKeys[$key])) {
            throw new RabbitMqException('Duplicate binding definition.', ErrorCode::TOPOLOGY_INVALID);
        }
        $this->bindingKeys[$key] = true;
        $this->bindings[] = $binding;
    }

    public function getExchanges(): array
    {
        return array_values($this->exchanges);
    }

    public function getQueues(): array
    {
        return array_values($this->queues);
    }

    public function getBindings(): array
    {
        return $this->bindings;
    }

    public function isEmpty(): bool
    {
        return empty($this->exchanges) && empty($this->queues) && empty($this->bindings);
    }

    public function validate(): void
    {
        $exchangeNames = array_keys($this->exchanges);
        $queueNames = array_keys($this->queues);

        foreach ($exchangeNames as $name) {
            if ($name === '') {
                throw new RabbitMqException('Exchange name cannot be empty.', ErrorCode::TOPOLOGY_INVALID);
            }
        }

        foreach ($queueNames as $name) {
            if ($name === '') {
                throw new RabbitMqException('Queue name cannot be empty.', ErrorCode::TOPOLOGY_INVALID);
            }
        }

        foreach ($this->bindings as $binding) {
            if (!isset($this->exchanges[$binding->getExchange()])) {
                throw new RabbitMqException(
                    "Binding references missing exchange '{$binding->getExchange()}'.",
                    ErrorCode::TOPOLOGY_INVALID
                );
            }
            if (!isset($this->queues[$binding->getQueue()])) {
                throw new RabbitMqException(
                    "Binding references missing queue '{$binding->getQueue()}'.",
                    ErrorCode::TOPOLOGY_INVALID
                );
            }
        }
    }

    private function isSameExchange(ExchangeDefinition $left, ExchangeDefinition $right): bool
    {
        return $left->getName() === $right->getName()
            && $left->getType() === $right->getType()
            && $left->isDurable() === $right->isDurable()
            && $left->isAutoDelete() === $right->isAutoDelete()
            && $left->isInternal() === $right->isInternal()
            && $left->getArguments() === $right->getArguments();
    }

    private function isSameQueue(QueueDefinition $left, QueueDefinition $right): bool
    {
        return $left->getName() === $right->getName()
            && $left->isDurable() === $right->isDurable()
            && $left->isAutoDelete() === $right->isAutoDelete()
            && $left->isExclusive() === $right->isExclusive()
            && $left->getArguments() === $right->getArguments();
    }

    private function bindingKey(BindingDefinition $binding): string
    {
        $args = $this->normalizeArguments($binding->getArguments());
        return $binding->getExchange()
            . '|' . $binding->getQueue()
            . '|' . $binding->getRoutingKey()
            . '|' . json_encode($args);
    }

    private function normalizeArguments(array $arguments): array
    {
        foreach ($arguments as $key => $value) {
            if (is_array($value)) {
                $arguments[$key] = $this->normalizeArguments($value);
            }
        }
        ksort($arguments);

        return $arguments;
    }
}
