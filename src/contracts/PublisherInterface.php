<?php

namespace illusiard\rabbitmq\contracts;

interface PublisherInterface
{
    public function publish(
        string $body,
        string $exchange = '',
        string $routingKey = '',
        array $properties = [],
        array $headers = []
    ): void;
}
