<?php

namespace illusiard\rabbitmq\consume;

use Yii;
use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\definitions\middleware\MiddlewareInterface;
use illusiard\rabbitmq\exceptions\ErrorCode;
use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\RecoverableException;

class ExceptionHandlingMiddleware implements MiddlewareInterface
{
    private ExceptionClassifierInterface $classifier;

    public function __construct(ExceptionClassifierInterface $classifier)
    {
        $this->classifier = $classifier;
    }

    public function process(ConsumeContext $context, callable $next)
    {
        try {
            $result = $next($context);
            return ConsumeResult::normalizeHandlerResult($result);
        } catch (\Throwable $e) {
            $result = $this->classifier->classify($e, $context);
            $isFatal = $result->getAction() === ConsumeResult::ACTION_STOP;
            $logMessage = $this->buildExceptionLogMessage($e, $context, $isFatal);

            if ($isFatal) {
                Yii::error($logMessage, 'rabbitmq');
                return $result;
            }

            if ($e instanceof RecoverableException) {
                Yii::warning($logMessage, 'rabbitmq');
            } else {
                Yii::error($logMessage, 'rabbitmq');
            }

            return $result;
        }
    }

    private function buildExceptionLogMessage(\Throwable $e, ConsumeContext $context, bool $fatal): string
    {
        $code = $e instanceof RabbitMqException ? $e->getErrorCode() : ErrorCode::CONSUME_FAILED;
        $meta = $context->getMeta();
        $properties = $meta->getProperties();
        $messageId = '';
        $correlationId = '';

        if (isset($properties['message_id'])) {
            $messageId = (string)$properties['message_id'];
        }
        if (isset($properties['correlation_id'])) {
            $correlationId = (string)$properties['correlation_id'];
        }

        $queue = $context->getConsumer()->getQueue();
        $prefix = $fatal ? 'FATAL ' : '';

        return $code . ' ' . $prefix
            . 'queue=' . $queue
            . ' message_id=' . $messageId
            . ' correlation_id=' . $correlationId
            . ' ' . get_class($e) . ': ' . $e->getMessage();
    }
}
