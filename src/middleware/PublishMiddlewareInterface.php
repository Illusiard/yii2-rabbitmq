<?php

namespace illusiard\rabbitmq\middleware;

use illusiard\rabbitmq\message\Envelope;

interface PublishMiddlewareInterface
{
    public function handle(Envelope $env, array $context, callable $next): void;
}
