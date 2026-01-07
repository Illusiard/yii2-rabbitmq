<?php

namespace illusiard\rabbitmq\middleware;

use Yii;
use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\ErrorCode;

class ConsumePipeline
{
    private array $middlewares;

    public function __construct(array $middlewares)
    {
        $this->middlewares = $middlewares;
    }

    public function run(string $body, array $meta, array $context, callable $final): bool
    {
        $runner = array_reduce(
            array_reverse($this->middlewares),
            function ($next, $middleware) use ($context) {
                return function (string $body, array $meta) use ($middleware, $context, $next) {
                    return $middleware->handle($body, $meta, $context, $next);
                };
            },
            function (string $body, array $meta) use ($final) {
                return $final($body, $meta);
            }
        );

        try {
            return (bool)$runner($body, $meta);
        } catch (\Throwable $e) {
            $code = $e instanceof RabbitMqException ? $e->getErrorCode() : ErrorCode::CONSUME_FAILED;
            Yii::error($code . ' ' . get_class($e) . ': ' . $e->getMessage(), 'rabbitmq');
            return false;
        }
    }
}
