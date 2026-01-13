<?php

namespace illusiard\rabbitmq\definitions\handler;

use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\message\Envelope;

class LegacyHandlerAdapter implements HandlerInterface
{
    /** @var callable */
    private $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function handle(Envelope $envelope): ConsumeResult
    {
        $result = ($this->handler)($envelope);

        return ConsumeResult::normalizeHandlerResult($result);
    }
}
