<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\definitions\DefinitionIdDeriver;
use PHPUnit\Framework\TestCase;

class DefinitionIdDeriverTest extends TestCase
{
    public function testDerivesConsumerIds(): void
    {
        $this->assertSame('events', DefinitionIdDeriver::derive('app\\EventsConsumer', 'Consumer'));
        $this->assertSame('audit-log', DefinitionIdDeriver::derive('app\\AuditLogConsumer', 'Consumer'));
    }

    public function testDerivesMiddlewareIds(): void
    {
        $this->assertSame('trace-id', DefinitionIdDeriver::derive('app\\TraceIdMiddleware', 'Middleware'));
        $this->assertSame('logger', DefinitionIdDeriver::derive('app\\Logger', 'Middleware'));
    }
}
