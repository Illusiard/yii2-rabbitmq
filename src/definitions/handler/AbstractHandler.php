<?php

namespace illusiard\rabbitmq\definitions\handler;

use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\message\Envelope;

abstract class AbstractHandler implements HandlerInterface
{
    abstract public function handle(Envelope $envelope): ConsumeResult|bool;
}
