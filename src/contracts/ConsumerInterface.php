<?php

namespace illusiard\rabbitmq\contracts;

interface ConsumerInterface
{
    public function consume(string $queue, callable $handler, int $prefetch = 1): void;
}
