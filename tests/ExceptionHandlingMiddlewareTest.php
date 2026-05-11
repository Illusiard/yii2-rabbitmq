<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\consume\DefaultExceptionClassifier;
use illusiard\rabbitmq\consume\ExceptionHandlingMiddleware;
use illusiard\rabbitmq\consume\RetryPolicyMiddleware;
use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\definitions\consume\MessageMeta;
use illusiard\rabbitmq\exceptions\ErrorCode;
use illusiard\rabbitmq\exceptions\RecoverableException;
use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\tests\fixtures\FakeRetryService;
use illusiard\rabbitmq\tests\fixtures\RecordingRetryPolicy;
use illusiard\rabbitmq\tests\fixtures\RetryTestConsumer;
use PHPUnit\Framework\TestCase;

class ExceptionHandlingMiddlewareTest extends TestCase
{
    public function testRecoverableExceptionIsPassedToRetryPolicyMiddlewareAsRetryResult(): void
    {
        $policy = new RecordingRetryPolicy();
        $retryMiddleware = new RetryPolicyMiddleware($policy);
        $middleware = new ExceptionHandlingMiddleware(
            new DefaultExceptionClassifier(true),
            static fn(ConsumeResult $result, ConsumeContext $context): ConsumeResult => $retryMiddleware->process(
                $context,
                static fn(): ConsumeResult => $result
            )
        );

        $result = $middleware->process($this->context(), static function (): void {
            throw new RecoverableException('temporary failure', ErrorCode::CONSUME_FAILED);
        });

        $this->assertSame(ConsumeResult::ACTION_ACK, $result->getAction());
        $this->assertInstanceOf(ConsumeResult::class, $policy->lastResult);
        $this->assertSame(ConsumeResult::ACTION_RETRY, $policy->lastResult->getAction());
    }

    private function context(): ConsumeContext
    {
        return new ConsumeContext(
            new Envelope('payload'),
            new MessageMeta([], [], 'payload', 1, 'routing', 'exchange', false),
            new FakeRetryService(),
            new RetryTestConsumer()
        );
    }
}
