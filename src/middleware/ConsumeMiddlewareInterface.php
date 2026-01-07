<?php

namespace illusiard\rabbitmq\middleware;

interface ConsumeMiddlewareInterface
{
    public function handle(string $body, array $meta, array $context, callable $next): bool;
}
