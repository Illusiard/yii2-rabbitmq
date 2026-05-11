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
}
