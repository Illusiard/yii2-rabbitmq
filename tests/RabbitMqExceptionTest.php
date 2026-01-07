<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\ErrorCode;

class RabbitMqExceptionTest extends TestCase
{
    public function testErrorCodeStored(): void
    {
        $e = new RabbitMqException('oops', ErrorCode::PUBLISH_FAILED);
        $this->assertSame(ErrorCode::PUBLISH_FAILED, $e->getErrorCode());
    }
}
