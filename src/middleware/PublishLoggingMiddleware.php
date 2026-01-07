<?php

namespace illusiard\rabbitmq\middleware;

use Yii;
use illusiard\rabbitmq\message\Envelope;

class PublishLoggingMiddleware implements PublishMiddlewareInterface
{
    public function handle(Envelope $env, array $context, callable $next): void
    {
        $exchange = $context['exchange'] ?? '';
        $routingKey = $context['routingKey'] ?? '';

        Yii::info(
            'Publish: exchange=' . $exchange
            . ' routingKey=' . $routingKey
            . ' message_id=' . $env->getMessageId()
            . ' correlation_id=' . ($env->getCorrelationId() ?? '')
            . ' type=' . ($env->getType() ?? ''),
            'rabbitmq'
        );

        $next($env);
    }
}
