<?php

namespace illusiard\rabbitmq\amqp;

use Yii;
use illusiard\rabbitmq\contracts\ReturnHandlerInterface;
use illusiard\rabbitmq\exceptions\ErrorCode;

class LoggingReturnHandler implements ReturnHandlerInterface
{
    public function handle(ReturnedMessage $event): void
    {
        $message = ErrorCode::PUBLISH_UNROUTABLE . ' Message returned by broker.'
            . ' reply_code=' . $event->replyCode
            . ' reply_text=' . $event->replyText
            . ' exchange=' . $event->exchange
            . ' routingKey=' . $event->routingKey
            . ' message_id=' . ($event->messageId ?? '')
            . ' correlation_id=' . ($event->correlationId ?? '')
            . ' body_size=' . $event->bodySize;

        Yii::warning($message, 'rabbitmq');
    }
}
