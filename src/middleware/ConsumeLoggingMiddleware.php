<?php

namespace illusiard\rabbitmq\middleware;

use Yii;
use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\ErrorCode;

class ConsumeLoggingMiddleware implements ConsumeMiddlewareInterface
{
    public function handle(string $body, array $meta, array $context, callable $next): bool
    {
        $queue = $context['queue'] ?? '';
        $handlerClass = $context['handlerClass'] ?? '';

        Yii::info('Consume start: queue=' . $queue . ' handler=' . $handlerClass, 'rabbitmq');

        try {
            $result = (bool)$next($body, $meta);
            Yii::info('Consume end: queue=' . $queue . ' handler=' . $handlerClass, 'rabbitmq');
            return $result;
        } catch (\Throwable $e) {
            $code = $e instanceof RabbitMqException ? $e->getErrorCode() : ErrorCode::HANDLER_FAILED;
            Yii::error($code . ' exception=' . get_class($e), 'rabbitmq');
            throw $e;
        }
    }
}
