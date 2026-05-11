<?php

namespace illusiard\rabbitmq\tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\consume\ManagedRetryPolicy;
use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\definitions\consume\MessageMeta;
use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\tests\fixtures\FakeRetryService;
use illusiard\rabbitmq\tests\fixtures\RetryTestConsumer;
use yii\base\InvalidConfigException;

class ManagedRetryPolicyTest extends TestCase
{
    /**
     * @return void
     * @throws InvalidConfigException
     */
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

    /**
     * @return void
     * @throws InvalidConfigException
     */
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
        $this->assertSame('retry-exchange', $service->published[0]['exchange']);
        $this->assertSame('retry_1', $service->published[0]['routingKey']);
        $this->assertSame(1, $service->published[0]['headers']['x-retry-count']);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
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
        $this->assertSame('', $service->published[0]['exchange']);
        $this->assertSame('dead', $service->published[0]['routingKey']);
        $this->assertSame(2, $service->published[0]['headers']['x-retry-count']);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testInvalidRetryCountIsSanitizedToZero(): void
    {
        $service = new FakeRetryService();
        $policy = new ManagedRetryPolicy($service, [
            'managedRetry' => [
                'enabled' => true,
                'maxAttempts' => 3,
                'retryQueues' => [
                    ['name' => 'retry_1', 'ttlMs' => 5000],
                ],
                'deadQueue' => 'dead',
            ],
        ]);

        $result = $policy->apply(ConsumeResult::retry(), $this->context($service, ['x-retry-count' => '2']));

        $this->assertSame(ConsumeResult::ACTION_ACK, $result->getAction());
        $this->assertSame('retry-exchange', $service->published[0]['exchange']);
        $this->assertSame('retry_1', $service->published[0]['routingKey']);
        $this->assertSame(1, $service->published[0]['headers']['x-retry-count']);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testRetryCountIsClampedToMaxAttempts(): void
    {
        $service = new FakeRetryService();
        $policy = new ManagedRetryPolicy($service, [
            'managedRetry' => [
                'enabled' => true,
                'maxAttempts' => 3,
                'retryQueues' => [
                    ['name' => 'retry_1', 'ttlMs' => 5000],
                ],
                'deadQueue' => 'dead',
            ],
        ]);

        $result = $policy->apply(ConsumeResult::retry(), $this->context($service, ['x-retry-count' => 999]));

        $this->assertSame(ConsumeResult::ACTION_ACK, $result->getAction());
        $this->assertSame('', $service->published[0]['exchange']);
        $this->assertSame('dead', $service->published[0]['routingKey']);
        $this->assertSame(4, $service->published[0]['headers']['x-retry-count']);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testEnabledPolicyRequiresMaxAttempts(): void
    {
        $service = new FakeRetryService();
        $policy = new ManagedRetryPolicy($service, [
            'managedRetry' => [
                'enabled' => true,
                'retryQueues' => [
                    ['name' => 'retry_1', 'ttlMs' => 5000],
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $policy->apply(ConsumeResult::retry(), $this->context($service, []));
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testExhaustedRetryCanStop(): void
    {
        $service = new FakeRetryService();
        $policy = new ManagedRetryPolicy($service, [
            'managedRetry' => [
                'enabled' => true,
                'maxAttempts' => 1,
                'retryQueues' => [
                    ['name' => 'retry_1', 'ttlMs' => 5000],
                ],
                'exhaustedAction' => 'stop',
            ],
        ]);

        $result = $policy->apply(ConsumeResult::retry(), $this->context($service, ['x-retry-count' => 1]));

        $this->assertSame(ConsumeResult::ACTION_STOP, $result->getAction());
        $this->assertCount(0, $service->published);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testRetryExchangeCanBeConfiguredAndTransientPropertiesAreDropped(): void
    {
        $service = new FakeRetryService();
        $policy = new ManagedRetryPolicy($service, [
            'managedRetry' => [
                'enabled' => true,
                'maxAttempts' => 3,
                'retryExchange' => 'custom-retry-ex',
                'retryQueues' => [
                    ['name' => 'retry_1', 'ttlMs' => 5000],
                ],
            ],
        ]);

        $meta = new MessageMeta(
            [],
            [
                'expiration' => '1',
                'reply_to' => 'reply.queue',
                'message_id' => 'msg-1',
            ],
            'payload',
            1,
            'routing',
            'exchange',
            false
        );
        $context = new ConsumeContext(new Envelope('payload'), $meta, $service, new RetryTestConsumer());

        $result = $policy->apply(ConsumeResult::retry(), $context);

        $this->assertSame(ConsumeResult::ACTION_ACK, $result->getAction());
        $this->assertSame('custom-retry-ex', $service->published[0]['exchange']);
        $this->assertSame('retry_1', $service->published[0]['routingKey']);
        $this->assertArrayNotHasKey('expiration', $service->published[0]['properties']);
        $this->assertArrayNotHasKey('reply_to', $service->published[0]['properties']);
        $this->assertSame('msg-1', $service->published[0]['properties']['message_id']);
    }

    private function context(FakeRetryService $service, array $headers): ConsumeContext
    {
        $meta = new MessageMeta($headers, [], 'payload', 1, 'routing', 'exchange', false);
        $envelope = new Envelope('payload');

        return new ConsumeContext($envelope, $meta, $service, new RetryTestConsumer());
    }
}
