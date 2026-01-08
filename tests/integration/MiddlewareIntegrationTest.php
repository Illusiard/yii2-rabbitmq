<?php

namespace illusiard\rabbitmq\tests\integration;

use illusiard\rabbitmq\middleware\ConsumeLoggingMiddleware;
use illusiard\rabbitmq\middleware\ConsumePipeline;
use illusiard\rabbitmq\middleware\CorrelationIdMiddleware;
use illusiard\rabbitmq\middleware\PublishLoggingMiddleware;

/**
 * @group integration
 */
class MiddlewareIntegrationTest extends IntegrationTestCase
{
    public function testAMQP_MW_01_correlationIdAndLogging(): void
    {
        $queue = $this->uniqueName('mw_q');
        $this->declareQueue($queue);

        $service = $this->createService([
            'publishMiddlewares' => [
                CorrelationIdMiddleware::class,
                PublishLoggingMiddleware::class,
            ],
        ]);
        $this->setService($service);

        $service->publishRawWithMiddlewares(
            'payload',
            '',
            $queue,
            [],
            ['x-trace-id' => 'trace-1']
        );

        $message = $this->waitForMessage($queue, 5);
        $this->assertNotNull($message);

        $properties = $message->get_properties();
        $this->assertNotEmpty($properties['correlation_id'] ?? null);

        $headers = [];
        if (isset($properties['application_headers'])) {
            $headers = $properties['application_headers']->getNativeData();
        }
        $this->assertSame('trace-1', $headers['x-trace-id'] ?? null);

        $pipeline = new ConsumePipeline([new ConsumeLoggingMiddleware()]);
        $result = $pipeline->run(
            $message->getBody(),
            ['properties' => $properties, 'headers' => $headers],
            ['queue' => $queue],
            function (): bool {
                return true;
            }
        );

        $this->assertTrue($result);
    }
}
