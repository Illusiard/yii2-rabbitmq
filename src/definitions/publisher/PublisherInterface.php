<?php

namespace illusiard\rabbitmq\definitions\publisher;

interface PublisherInterface
{
    public function getExchange(): string;

    public function getRoutingKey(): string;

    public function getOptions(): array;

    public function getMiddlewares(): array;
}
