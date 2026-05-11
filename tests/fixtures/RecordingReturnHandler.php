<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\amqp\ReturnedMessage;
use illusiard\rabbitmq\contracts\ReturnHandlerInterface;

class RecordingReturnHandler implements ReturnHandlerInterface
{
    public ?ReturnedMessage $event = null;

    public function handle(ReturnedMessage $event): void
    {
        $this->event = $event;
    }
}
