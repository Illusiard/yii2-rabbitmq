<?php

namespace illusiard\rabbitmq\profile;

use illusiard\rabbitmq\definitions\publisher\PublisherInterface;

class ProfiledPublisher implements PublisherInterface
{
    private PublisherInterface $publisher;
    private array $options;
    private array $middlewares;

    public function __construct(PublisherInterface $publisher, array $options, array $middlewares)
    {
        $this->publisher = $publisher;
        $this->options = $options;
        $this->middlewares = $middlewares;
    }

    public function getExchange(): string
    {
        return $this->publisher->getExchange();
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    public function getRoutingKey(): string
    {
        return $this->publisher->getRoutingKey();
    }

    public function __call(string $name, array $arguments)
    {
        return $this->publisher->{$name}(...$arguments);
    }
}
