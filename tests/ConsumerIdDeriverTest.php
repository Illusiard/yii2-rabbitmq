<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\consumer\ConsumerIdDeriver;

class ConsumerIdDeriverTest extends TestCase
{
    public function testDerivesIdsFromClassNames(): void
    {
        $this->assertSame('events', ConsumerIdDeriver::derive('EventsConsumer'));
        $this->assertSame('audit-log', ConsumerIdDeriver::derive('AuditLogConsumer'));
        $this->assertSame('foo', ConsumerIdDeriver::derive('Foo'));
    }
}
