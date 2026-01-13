<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\consume\ManagedRetryPolicy;
use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\definitions\consume\MessageMeta;
use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\message\Envelope;

class ManagedRetryPolicyTest extends TestCase
{
    public function testDisabledMapsRetryToReject(): void
    {
        $service = new FakeRetryService();
        $policy = new ManagedRetryPolicy($service, [
            'managedRetry' => false,
        ]);

        $result = $policy->apply(ConsumeResult::retry(), $this->context($service, []));

        $this->assertSame(ConsumeResult::ACTION_REJECT, $result->getAction());
        $this->assertFalse($result->shouldRequeue());
        $this->assertCount(0, $service->published);
    }

    public function testPublishesToRetryQueueAndIncrementsHeader(): void
    {
        $service = new FakeRetryService();
        $policy = new ManagedRetryPolicy($service, [
            'managedRetry' => [
                'enabled' => true,
                'maxAttempts' => 3,
                'retryQueues' => [
                    ['name' => 'retry_1', 'ttlMs' => 5000],
                    ['name' => 'retry_2', 'ttlMs' => 30000],
                ],
                'deadQueue' => 'dead',
            ],
        ]);

        $result = $policy->apply(ConsumeResult::retry(), $this->context($service, []));

        $this->assertSame(ConsumeResult::ACTION_ACK, $result->getAction());
        $this->assertCount(1, $service->published);
        $this->assertSame('retry_1', $service->published[0]['routingKey']);
        $this->assertSame(1, $service->published[0]['headers']['x-retry-count']);
    }

    public function testDeadQueueAfterMaxAttempts(): void
    {
        $service = new FakeRetryService();
        $policy = new ManagedRetryPolicy($service, [
            'managedRetry' => [
                'enabled' => true,
                'maxAttempts' => 1,
                'retryQueues' => [
                    ['name' => 'retry_1', 'ttlMs' => 5000],
                ],
                'deadQueue' => 'dead',
            ],
        ]);

        $headers = ['x-retry-count' => 1];
        $result = $policy->apply(ConsumeResult::retry(), $this->context($service, $headers));

        $this->assertSame(ConsumeResult::ACTION_ACK, $result->getAction());
        $this->assertSame('dead', $service->published[0]['routingKey']);
        $this->assertSame(2, $service->published[0]['headers']['x-retry-count']);
    }

    private function context(FakeRetryService $service, array $headers): ConsumeContext
    {
        $consumer = new class implements ConsumerInterface {
            public function getQueue(): string
            {
                return 'queue';
            }

            public function getHandler()
            {
                return function (): bool {
                    return false;
                };
            }

            public function getOptions(): array
            {
                return [];
            }

            public function getMiddlewares(): array
            {
                return [];
            }
        };

        $meta = new MessageMeta($headers, [], 'payload', 1, 'routing', 'exchange', false);
        $envelope = new Envelope('payload');

        return new ConsumeContext($envelope, $meta, $service, $consumer);
    }
}

class FakeRetryService extends RabbitMqService
{
    public array $published = [];

    public function publish(
        string $body,
        string $exchange = '',
        string $routingKey = '',
        array $properties = [],
        array $headers = []
    ): void {
        $this->published[] = [
            'body' => $body,
            'exchange' => $exchange,
            'routingKey' => $routingKey,
            'properties' => $properties,
            'headers' => $headers,
        ];
    }
}
