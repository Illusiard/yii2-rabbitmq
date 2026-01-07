<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\middleware\PublishPipeline;
use illusiard\rabbitmq\middleware\PublishMiddlewareInterface;
use illusiard\rabbitmq\message\Envelope;

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
            unset($env);
            $calls[] = 'final';
        });

        $this->assertSame(['m1', 'm2', 'final'], $calls);
    }
}

class TestPublishMiddleware implements PublishMiddlewareInterface
{
    private string $name;
    private array $calls;

    public function __construct(string $name, array &$calls)
    {
        $this->name = $name;
        $this->calls = &$calls;
    }

    public function handle(Envelope $env, array $context, callable $next): void
    {
        $this->calls[] = $this->name;
        $next($env);
    }
}
