<?php

namespace illusiard\rabbitmq\amqp;

interface ReturnSinkInterface
{
    public function onReturned(ReturnedMessage $event): void;

    public function onAck(int $deliveryTag, bool $multiple): void;

    public function onNack(int $deliveryTag, bool $multiple): void;
}
