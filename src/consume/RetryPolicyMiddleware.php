<?php

namespace illusiard\rabbitmq\consume;

use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\definitions\middleware\MiddlewareInterface;

class RetryPolicyMiddleware implements MiddlewareInterface
{
    private RetryPolicyInterface $policy;

    public function __construct(RetryPolicyInterface $policy)
    {
        $this->policy = $policy;
    }

    public function process(ConsumeContext $context, callable $next)
    {
        $result = $next($context);
        $normalized = ConsumeResult::normalizeHandlerResult($result);

        return $this->policy->apply($normalized, $context);
    }
}
