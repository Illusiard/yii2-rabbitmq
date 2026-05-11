<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\definitions\middleware\MiddlewareInterface;

class TestRunnerUserMiddleware implements MiddlewareInterface
{
    public static array $actions = [];

    public function process(ConsumeContext $context, callable $next): ConsumeResult
    {
        self::$actions[] = 'user-before';
        $result = $next($context);
        $normalized = ConsumeResult::normalizeHandlerResult($result);
        self::$actions[] = 'user-after-' . $normalized->getAction();

        return $normalized;
    }
}
