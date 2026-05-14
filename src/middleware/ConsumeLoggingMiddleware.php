<?php

namespace illusiard\rabbitmq\middleware;

use Throwable;
use Yii;
use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\definitions\middleware\MiddlewareInterface;
use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\ErrorCode;

class ConsumeLoggingMiddleware implements MiddlewareInterface, ConsumeMiddlewareInterface
{
    /**
     * @param ConsumeContext $context
     * @param callable $next
     * @return ConsumeResult
     * @throws Throwable
     */
    public function process(ConsumeContext $context, callable $next): ConsumeResult
    {
        $queue = $context->getConsumer()->getQueue();

        Yii::info('Consume start: queue=' . $queue, 'rabbitmq');

        try {
            $result = ConsumeResult::normalizeHandlerResult($next($context));
            Yii::info('Consume end: queue=' . $queue . ' action=' . $result->getAction(), 'rabbitmq');
            return $result;
        } catch (Throwable $e) {
            $this->logException($e);
            throw $e;
        }
    }

    /**
     * @param string $body
     * @param array $meta
     * @param array $context
     * @param callable $next
     * @return bool
     * @throws Throwable
     */
    public function handle(string $body, array $meta, array $context, callable $next): bool
    {
        $queue = $context['queue'] ?? '';
        $handlerClass = $context['handlerClass'] ?? '';

        Yii::info('Consume start: queue=' . $queue . ' handler=' . $handlerClass, 'rabbitmq');

        try {
            $result = (bool)$next($body, $meta);
            Yii::info('Consume end: queue=' . $queue . ' handler=' . $handlerClass, 'rabbitmq');
            return $result;
        } catch (Throwable $e) {
            $this->logException($e);
            throw $e;
        }
    }

    private function logException(Throwable $e): void
    {
        $code = $e instanceof RabbitMqException ? $e->getErrorCode() : ErrorCode::HANDLER_FAILED;
        Yii::error($code . ' exception=' . get_class($e), 'rabbitmq');
    }
}
