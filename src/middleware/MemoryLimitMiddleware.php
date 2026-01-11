<?php

namespace illusiard\rabbitmq\middleware;

class MemoryLimitMiddleware implements ConsumeMiddlewareInterface
{
    public int $memoryLimitBytes = 0;

    public function handle(string $body, array $meta, array $context, callable $next): bool
    {
        if ($this->memoryLimitBytes > 0 && memory_get_usage(true) > $this->memoryLimitBytes) {
            throw new \RuntimeException('Memory limit exceeded.');
        }

        return (bool)$next($body, $meta);
    }
}
