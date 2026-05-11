<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\tests\fixtures\TestConsumerDefinition;
use illusiard\rabbitmq\tests\fixtures\TestProfile;
use illusiard\rabbitmq\tests\fixtures\TestPublisherDefinition;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use yii\base\InvalidConfigException;

class ProfileDefaultsTest extends TestCase
{
    /**
     * @return void
     * @throws ReflectionException
     * @throws InvalidConfigException
     */
    public function testProfileDefaultsAppliedToConsumerAndPublisher(): void
    {
        $service = new RabbitMqService([
            'profile' => new TestProfile(),
        ]);

        $consumer = $service->createConsumerDefinition(TestConsumerDefinition::class);
        $this->assertSame([
            'prefetch' => 1,
            'retryPolicy' => [
                'delaySeconds' => 10,
            ],
        ], $consumer->getOptions());
        $this->assertSame(['base-consume', 'consume-extra'], $consumer->getMiddlewares());

        $publisher = $service->createPublisherDefinition(TestPublisherDefinition::class);
        $this->assertSame([
            'confirm' => true,
            'headers' => [
                'x-service' => 'test',
            ],
        ], $publisher->getOptions());
        $this->assertSame(['base-publish', 'publish-extra'], $publisher->getMiddlewares());
    }

    /**
     * @return void
     * @throws ReflectionException
     * @throws InvalidConfigException
     */
    public function testComponentConsumeOptionsOverrideProfileAndConsumerDefaults(): void
    {
        $service = new RabbitMqService([
            'profile' => new TestProfile(),
            'managedRetry' => true,
            'retryPolicy' => [
                'maxAttempts' => 4,
                'retryQueues' => [
                    ['name' => 'component.retry.1', 'ttlMs' => 5000],
                ],
                'deadQueue' => 'component.dead',
            ],
            'consumeFailFast' => false,
            'consumeMiddlewares' => ['component-consume'],
        ]);

        $consumer = $service->createConsumerDefinition(TestConsumerDefinition::class);

        $this->assertSame([
            'prefetch' => 1,
            'retryPolicy' => [
                'delaySeconds' => 10,
                'maxAttempts' => 4,
                'retryQueues' => [
                    ['name' => 'component.retry.1', 'ttlMs' => 5000],
                ],
                'deadQueue' => 'component.dead',
            ],
            'managedRetry' => true,
            'consumeFailFast' => false,
        ], $consumer->getOptions());
        $this->assertSame(['base-consume', 'consume-extra', 'component-consume'], $consumer->getMiddlewares());
    }
}
