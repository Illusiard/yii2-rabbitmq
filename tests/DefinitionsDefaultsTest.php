<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\definitions\consumer\AbstractConsumer;
use illusiard\rabbitmq\definitions\publisher\AbstractPublisher;
use PHPUnit\Framework\TestCase;

class DefinitionsDefaultsTest extends TestCase
{
    public function testAbstractConsumerDefaults(): void
    {
        $consumer = new class extends AbstractConsumer {
            public function getQueue(): string
            {
                return 'queue';
            }

            public function getHandler()
            {
                return function (): bool {
                    return true;
                };
            }
        };

        $this->assertSame([], $consumer->getOptions());
        $this->assertSame([], $consumer->getMiddlewares());
    }

    public function testAbstractPublisherDefaults(): void
    {
        $publisher = new class extends AbstractPublisher {
            public function getExchange(): string
            {
                return 'exchange';
            }
        };

        $this->assertSame([], $publisher->getOptions());
        $this->assertSame([], $publisher->getMiddlewares());
        $this->assertSame('', $publisher->getRoutingKey());
    }
}
