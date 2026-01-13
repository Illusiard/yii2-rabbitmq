<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use PHPUnit\Framework\TestCase;

class ConsumeResultTest extends TestCase
{
    public function testFromBool(): void
    {
        $this->assertSame(ConsumeResult::ACTION_ACK, ConsumeResult::fromLegacyBool(true)->getAction());
        $this->assertSame(ConsumeResult::ACTION_RETRY, ConsumeResult::fromLegacyBool(false)->getAction());
    }

    public function testNormalizeHandlerResult(): void
    {
        $ack = ConsumeResult::ack();
        $this->assertSame($ack, ConsumeResult::normalizeHandlerResult($ack));

        $this->assertSame(ConsumeResult::ACTION_ACK, ConsumeResult::normalizeHandlerResult(true)->getAction());
        $this->assertSame(ConsumeResult::ACTION_RETRY, ConsumeResult::normalizeHandlerResult(false)->getAction());
    }
}
