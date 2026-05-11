<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\middleware\PublishMiddlewareInterface;

class TestPublishMiddleware implements PublishMiddlewareInterface
{
    private string $name;
    private array $calls;

    public function __construct(string $name, array &$calls)
    {
        $this->name = $name;
        $this->calls = &$calls;
    }

    public function handle(Envelope $env, array $context, callable $next): void
    {
        $this->calls[] = $this->name;
        $next($env);
    }
}
