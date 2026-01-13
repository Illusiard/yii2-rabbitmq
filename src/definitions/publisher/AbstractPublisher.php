<?php

namespace illusiard\rabbitmq\definitions\publisher;

abstract class AbstractPublisher implements PublisherInterface
{
    public function getOptions(): array
    {
        return [];
    }

    public function getMiddlewares(): array
    {
        return [];
    }

    public function getRoutingKey(): string
    {
        return '';
    }
}
