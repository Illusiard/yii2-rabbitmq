<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\tests\fixtures\DefaultConsumerDefinition;
use illusiard\rabbitmq\tests\fixtures\DefaultPublisherDefinition;
use PHPUnit\Framework\TestCase;

class DefinitionsDefaultsTest extends TestCase
{
    public function testAbstractConsumerDefaults(): void
    {
        $consumer = new DefaultConsumerDefinition();

        $this->assertSame([], $consumer->getOptions());
        $this->assertSame([], $consumer->getMiddlewares());
    }

    public function testAbstractPublisherDefaults(): void
    {
        $publisher = new DefaultPublisherDefinition();

        $this->assertSame([], $publisher->getOptions());
        $this->assertSame([], $publisher->getMiddlewares());
        $this->assertSame('', $publisher->getRoutingKey());
    }
}
