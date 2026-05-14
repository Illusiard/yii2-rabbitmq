<?php

namespace illusiard\rabbitmq\middleware;

use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\definitions\middleware\MiddlewareInterface;
use RuntimeException;

class MemoryLimitMiddleware implements MiddlewareInterface, ConsumeMiddlewareInterface
{
    public int $memoryLimitBytes = 0;

    public function process(ConsumeContext $context, callable $next): ConsumeResult
    {
        $this->assertMemoryLimit();

        return ConsumeResult::normalizeHandlerResult($next($context));
    }

    public function handle(string $body, array $meta, array $context, callable $next): bool
    {
        $this->assertMemoryLimit();

        return (bool)$next($body, $meta);
    }

    private function assertMemoryLimit(): void
    {
        if ($this->memoryLimitBytes > 0 && memory_get_usage(true) > $this->memoryLimitBytes) {
            throw new RuntimeException('Memory limit exceeded.');
        }
    }
}
