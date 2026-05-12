<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\definitions\middleware\MiddlewareInterface;

class CountingConsumeMiddleware implements MiddlewareInterface
{
    public static int $calls = 0;

    public function process(ConsumeContext $context, callable $next): ConsumeResult
    {
        self::$calls++;

        return ConsumeResult::normalizeHandlerResult($next($context));
    }
}
