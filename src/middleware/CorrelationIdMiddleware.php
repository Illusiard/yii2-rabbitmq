<?php

namespace illusiard\rabbitmq\middleware;

use illusiard\rabbitmq\message\Envelope;

class CorrelationIdMiddleware implements PublishMiddlewareInterface
{
    public function handle(Envelope $env, array $context, callable $next): void
    {
        $correlationId = $env->getCorrelationId();
        if ($correlationId === null || $correlationId === '') {
            $correlationId = $this->generateCorrelationId();
            $env = $env->withCorrelationId($correlationId);
        }

        $env = $env->withProperty('correlation_id', $correlationId);
        $next($env);
    }

    private function generateCorrelationId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return uniqid('corr_', true);
        }
    }
}
