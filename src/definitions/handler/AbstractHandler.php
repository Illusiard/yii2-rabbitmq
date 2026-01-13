<?php

namespace illusiard\rabbitmq\definitions\handler;

use illusiard\rabbitmq\message\Envelope;

abstract class AbstractHandler implements HandlerInterface
{
    abstract public function handle(Envelope $envelope);
}
