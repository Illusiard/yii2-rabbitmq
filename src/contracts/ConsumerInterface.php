<?php

namespace illusiard\rabbitmq\contracts;

interface ConsumerInterface
{
    public function consume(string $queue, callable $handler, array $options = []): void;
}
