<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\definitions\consumer\AbstractConsumer;
use illusiard\rabbitmq\definitions\publisher\AbstractPublisher;
use illusiard\rabbitmq\profile\OptionsMerger;
use illusiard\rabbitmq\profile\RabbitMqProfileInterface;
use PHPUnit\Framework\TestCase;

class ProfileDefaultsTest extends TestCase
{
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

class TestProfile implements RabbitMqProfileInterface
{
    public function getConsumerDefaults(): array
    {
        return [
            'prefetch' => 5,
            'retryPolicy' => [
                'maxAttempts' => 3,
                'delaySeconds' => 5,
            ],
        ];
    }

    public function getPublisherDefaults(): array
    {
        return [
            'confirm' => false,
            'headers' => [
                'x-service' => 'test',
            ],
        ];
    }

    public function getMiddlewareDefaults(): array
    {
        return [
            'consumer' => ['base-consume'],
            'publisher' => ['base-publish'],
        ];
    }

    public function mergeOptions(array $defaults, array $overrides): array
    {
        return OptionsMerger::merge($defaults, $overrides);
    }
}

class TestConsumerDefinition extends AbstractConsumer
{
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

    public function getOptions(): array
    {
        return [
            'prefetch' => 1,
            'retryPolicy' => [
                'maxAttempts' => null,
                'delaySeconds' => 10,
            ],
        ];
    }

    public function getMiddlewares(): array
    {
        return ['consume-extra'];
    }
}

class TestPublisherDefinition extends AbstractPublisher
{
    public function getExchange(): string
    {
        return 'exchange';
    }

    public function getOptions(): array
    {
        return [
            'confirm' => true,
        ];
    }

    public function getMiddlewares(): array
    {
        return ['publish-extra'];
    }
}
