<?php

namespace illusiard\rabbitmq\topology;

class BindingDefinition
{
    private string $exchange;
    private string $queue;
    private string $routingKey;
    private array $arguments;

    public function __construct(
        string $exchange,
        string $queue,
        string $routingKey = '',
        array $arguments = []
    ) {
        $this->exchange = $exchange;
        $this->queue = $queue;
        $this->routingKey = $routingKey;
        $this->arguments = $arguments;
    }

    public function getExchange(): string
    {
        return $this->exchange;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getRoutingKey(): string
    {
        return $this->routingKey;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }
}
