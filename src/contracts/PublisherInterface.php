<?php

namespace illusiard\rabbitmq\contracts;

interface PublisherInterface
{
    public function publish(string $exchange, string $routingKey, string $body, array $properties = []): void;
}
