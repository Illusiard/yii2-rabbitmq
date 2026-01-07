<?php

namespace illusiard\rabbitmq\middleware;

use illusiard\rabbitmq\message\Envelope;

class PublishPipeline
{
    private array $middlewares;

    public function __construct(array $middlewares)
    {
        $this->middlewares = $middlewares;
    }

    public function run(Envelope $env, array $context, callable $final): void
    {
        $runner = array_reduce(
            array_reverse($this->middlewares),
            function ($next, $middleware) use ($context) {
                return function (Envelope $env) use ($middleware, $context, $next) {
                    return $middleware->handle($env, $context, $next);
                };
            },
            function (Envelope $env) use ($final) {
                return $final($env);
            }
        );

        $runner($env);
    }
}
