<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\middleware\CorrelationIdMiddleware;
use illusiard\rabbitmq\message\Envelope;

class CorrelationIdMiddlewareTest extends TestCase
{
    public function testSetsCorrelationIdAndProperty(): void
    {
        $middleware = new CorrelationIdMiddleware();
        $env = new Envelope(['a' => 1]);

        $captured = null;
        $middleware->handle($env, [], function (Envelope $env) use (&$captured) {
            $captured = $env;
        });

        $this->assertNotNull($captured);
        $this->assertNotEmpty($captured->getCorrelationId());
        $this->assertSame($captured->getCorrelationId(), $captured->getProperties()['correlation_id']);
    }
}
