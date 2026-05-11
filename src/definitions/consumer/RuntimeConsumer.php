<?php

namespace illusiard\rabbitmq\definitions\consumer;

class RuntimeConsumer implements ConsumerInterface
{
    private string $queue;
    private $handler;
    private array $options;

    public function __construct(string $queue, $handler, array $options)
    {
        $this->queue = $queue;
        $this->handler = $handler;
        $this->options = $options;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getHandler()
    {
        return $this->handler;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getMiddlewares(): array
    {
        return [];
    }
}
