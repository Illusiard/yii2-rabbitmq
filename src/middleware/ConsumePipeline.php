<?php

namespace illusiard\rabbitmq\middleware;

use Yii;
use illusiard\rabbitmq\consume\ExceptionClassifier;
use illusiard\rabbitmq\exceptions\ErrorCode;
use illusiard\rabbitmq\exceptions\FatalException;
use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\RecoverableException;

class ConsumePipeline
{
    private array $middlewares;
    private ExceptionClassifier $classifier;

    public function __construct(array $middlewares, ?ExceptionClassifier $classifier = null)
    {
        $this->middlewares = $middlewares;
        $this->classifier = $classifier ?? new ExceptionClassifier();
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
            $classification = $this->classifier->classify($e);
            $isFatal = $classification === ExceptionClassifier::RESULT_FATAL;
            $logMessage = $this->buildExceptionLogMessage($e, $meta, $context, $isFatal);

            if ($isFatal) {
                Yii::error($logMessage, 'rabbitmq');

                if ($e instanceof FatalException) {
                    throw $e;
                }

                $code = $e instanceof RabbitMqException ? $e->getErrorCode() : ErrorCode::CONSUME_FAILED;
                throw new FatalException($e->getMessage(), $code, 0, $e);
            }

            if ($e instanceof RecoverableException) {
                Yii::warning($logMessage, 'rabbitmq');
            } else {
                Yii::error($logMessage, 'rabbitmq');
            }

            return false;
        }
    }

    private function buildExceptionLogMessage(\Throwable $e, array $meta, array $context, bool $fatal): string
    {
        $code = $e instanceof RabbitMqException ? $e->getErrorCode() : ErrorCode::CONSUME_FAILED;
        $properties = $meta['properties'] ?? [];
        $messageId = '';
        $correlationId = '';

        if (is_array($properties)) {
            if (isset($properties['message_id'])) {
                $messageId = (string)$properties['message_id'];
            }
            if (isset($properties['correlation_id'])) {
                $correlationId = (string)$properties['correlation_id'];
            }
        }

        $queue = isset($context['queue']) ? (string)$context['queue'] : '';
        $prefix = $fatal ? 'FATAL ' : '';

        return $code . ' ' . $prefix
            . 'queue=' . $queue
            . ' message_id=' . $messageId
            . ' correlation_id=' . $correlationId
            . ' exception=' . get_class($e);
    }
}
