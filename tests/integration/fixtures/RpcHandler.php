<?php

namespace illusiard\rabbitmq\tests\integration\fixtures;

use illusiard\rabbitmq\message\Envelope;

class RpcHandler
{
    public function __invoke(Envelope $request): Envelope
    {
        return new Envelope(['ok' => true], [], [], 'rpc.reply', $request->getCorrelationId());
    }
}
