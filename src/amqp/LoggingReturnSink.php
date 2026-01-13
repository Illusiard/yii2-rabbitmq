<?php

namespace illusiard\rabbitmq\amqp;

use Yii;

class LoggingReturnSink implements ReturnSinkInterface
{
    public function onReturned(ReturnedMessage $event): void
    {
        Yii::warning(
            'PUBLISH_UNROUTABLE replyCode=' . $event->replyCode
            . ' replyText=' . $event->replyText
            . ' exchange=' . $event->exchange
            . ' routingKey=' . $event->routingKey
            . ' message_id=' . ($event->messageId ?? '')
            . ' correlation_id=' . ($event->correlationId ?? ''),
            'rabbitmq'
        );
    }

    public function onAck(int $deliveryTag, bool $multiple): void
    {
        Yii::info(
            'Publish ACK deliveryTag=' . $deliveryTag . ' multiple=' . ($multiple ? '1' : '0'),
            'rabbitmq'
        );
    }

    public function onNack(int $deliveryTag, bool $multiple): void
    {
        Yii::warning(
            'Publish NACK deliveryTag=' . $deliveryTag . ' multiple=' . ($multiple ? '1' : '0'),
            'rabbitmq'
        );
    }
}
