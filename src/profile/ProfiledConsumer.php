<?php

namespace illusiard\rabbitmq\profile;

use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;

class ProfiledConsumer implements ConsumerInterface
{
    private ConsumerInterface $consumer;
    private array $options;
    private array $middlewares;

    public function __construct(ConsumerInterface $consumer, array $options, array $middlewares)
    {
        $this->consumer = $consumer;
        $this->options = $options;
        $this->middlewares = $middlewares;
    }

    public function getQueue(): string
    {
        return $this->consumer->getQueue();
    }

    public function getHandler()
    {
        return $this->consumer->getHandler();
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    public function __call(string $name, array $arguments)
    {
        return $this->consumer->{$name}(...$arguments);
    }
}
