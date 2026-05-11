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

    /**
     * @param ConsumeContext $context
     * @param callable $next
     * @return ConsumeResult
     */
    public function process(ConsumeContext $context, callable $next): ConsumeResult
    {
        $result = $next($context);
        $normalized = ConsumeResult::normalizeHandlerResult($result);

        return $this->policy->apply($normalized, $context);
    }
}
