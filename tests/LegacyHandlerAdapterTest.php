<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\definitions\handler\LegacyHandlerAdapter;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\message\Envelope;
use PHPUnit\Framework\TestCase;

class LegacyHandlerAdapterTest extends TestCase
{
    public function testAdapterNormalizesResult(): void
    {
        $adapter = new LegacyHandlerAdapter(function (Envelope $envelope): bool {
            return $envelope->getPayload()['ok'] ?? false;
        });

        $result = $adapter->handle(new Envelope(['ok' => true]));

        $this->assertInstanceOf(ConsumeResult::class, $result);
        $this->assertSame(ConsumeResult::ACTION_ACK, $result->getAction());
    }
}
