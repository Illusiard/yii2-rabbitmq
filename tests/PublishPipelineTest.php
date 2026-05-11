<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\middleware\PublishPipeline;
use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\tests\fixtures\TestPublishMiddleware;

class PublishPipelineTest extends TestCase
{
    public function testOrder(): void
    {
        $calls = [];
        $middlewares = [
            new TestPublishMiddleware('m1', $calls),
            new TestPublishMiddleware('m2', $calls),
        ];

        $pipeline = new PublishPipeline($middlewares);
        $pipeline->run(new Envelope(['a' => 1]), [], function (Envelope $env) use (&$calls) {
            $calls[] = 'final';
        });

        $this->assertSame(['m1', 'm2', 'final'], $calls);
    }
}
