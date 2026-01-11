<?php

namespace illusiard\rabbitmq\contracts;

use illusiard\rabbitmq\amqp\ReturnedMessage;

interface ReturnHandlerInterface
{
    public function handle(ReturnedMessage $event): void;
}
