<?php

namespace illusiard\rabbitmq\tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\topology\Topology;
use illusiard\rabbitmq\topology\ExchangeDefinition;
use illusiard\rabbitmq\topology\QueueDefinition;
use illusiard\rabbitmq\topology\BindingDefinition;
use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\ErrorCode;

class TopologyTest extends TestCase
{
    public function testDuplicateExchangeDefinitionThrows(): void
    {
        $topology = new Topology();
        $topology->addExchange(new ExchangeDefinition('ex', 'direct'));

        try {
            $topology->addExchange(new ExchangeDefinition('ex', 'fanout'));
            $this->fail('Expected topology validation error.');
        } catch (RabbitMqException $e) {
            $this->assertSame(ErrorCode::TOPOLOGY_INVALID, $e->getErrorCode());
        }
    }

    public function testDuplicateQueueDefinitionThrows(): void
    {
        $topology = new Topology();
        $topology->addQueue(new QueueDefinition('q', true));

        try {
            $topology->addQueue(new QueueDefinition('q', false));
            $this->fail('Expected topology validation error.');
        } catch (RabbitMqException $e) {
            $this->assertSame(ErrorCode::TOPOLOGY_INVALID, $e->getErrorCode());
        }
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testDuplicateBindingDefinitionIsIdempotent(): void
    {
        $topology = new Topology();
        $topology->addExchange(new ExchangeDefinition('ex'));
        $topology->addQueue(new QueueDefinition('q'));
        $topology->addBinding(new BindingDefinition('ex', 'q', 'rk'));
        $topology->addBinding(new BindingDefinition('ex', 'q', 'rk'));

        $this->assertCount(1, $topology->getBindings());
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testValidationFailsWhenBindingReferencesMissingExchange(): void
    {
        $topology = new Topology();
        $topology->addQueue(new QueueDefinition('q'));
        $topology->addBinding(new BindingDefinition('missing', 'q', 'rk'));

        try {
            $topology->validate();
            $this->fail('Expected topology validation error.');
        } catch (RabbitMqException $e) {
            $this->assertSame(ErrorCode::TOPOLOGY_INVALID, $e->getErrorCode());
        }
    }
}
