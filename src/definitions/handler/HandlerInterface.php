<?php

namespace illusiard\rabbitmq\definitions\handler;

use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\message\Envelope;

interface HandlerInterface
{
    public function handle(Envelope $envelope): ConsumeResult|bool;
}
