<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\consume\DefaultExceptionClassifier;
use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\definitions\consume\MessageMeta;
use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\exceptions\FatalException;
use illusiard\rabbitmq\exceptions\RecoverableException;

class DefaultExceptionClassifierTest extends TestCase
{
    public function testFatalExceptionStops(): void
    {
        $classifier = new DefaultExceptionClassifier(true);
        $result = $classifier->classify(new FatalException('boom', 'E_FATAL'), $this->context());

        $this->assertSame(ConsumeResult::ACTION_STOP, $result->getAction());
    }

    public function testRecoverableExceptionRetries(): void
    {
        $classifier = new DefaultExceptionClassifier(true);
        $result = $classifier->classify(new RecoverableException('oops', 'E_RECOVER'), $this->context());

        $this->assertSame(ConsumeResult::ACTION_RETRY, $result->getAction());
    }

    public function testFailFastFalseRetriesOnUnknown(): void
    {
        $classifier = new DefaultExceptionClassifier(false);
        $result = $classifier->classify(new \RuntimeException('unknown'), $this->context());

        $this->assertSame(ConsumeResult::ACTION_RETRY, $result->getAction());
    }

    public function testConfiguredFatalMatchesStop(): void
    {
        $classifier = new DefaultExceptionClassifier(true, [\RuntimeException::class], []);
        $result = $classifier->classify(new \RuntimeException('boom'), $this->context());

        $this->assertSame(ConsumeResult::ACTION_STOP, $result->getAction());
    }

    public function testConfiguredRecoverableMatchesRetry(): void
    {
        $classifier = new DefaultExceptionClassifier(true, [], [\RuntimeException::class]);
        $result = $classifier->classify(new \RuntimeException('retry'), $this->context());

        $this->assertSame(ConsumeResult::ACTION_RETRY, $result->getAction());
    }

    private function context(): ConsumeContext
    {
        $consumer = new class implements ConsumerInterface {
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
                return [];
            }

            public function getMiddlewares(): array
            {
                return [];
            }
        };

        $meta = new MessageMeta([], [], 'payload', 1, 'routing', 'exchange', false);
        $envelope = new Envelope('payload');
        $service = new RabbitMqService();

        return new ConsumeContext($envelope, $meta, $service, $consumer);
    }
}
