<?php

namespace illusiard\rabbitmq\definitions\middleware;

use illusiard\rabbitmq\definitions\consume\ConsumeContext;

abstract class AbstractMiddleware implements MiddlewareInterface
{
    public function process(ConsumeContext $context, callable $next)
    {
        return $next($context);
    }
}
