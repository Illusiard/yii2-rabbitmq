<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\retry\RetryDecider;

class RetryDeciderTest extends TestCase
{
    public function testAttemptsDefaultToZeroWhenHeaderMissing(): void
    {
        $decider = new RetryDecider();
        $decision = $decider->decide([
            'headers' => [],
        ], [
            'maxAttempts' => 5,
            'retryQueues' => [
                ['name' => 'r1', 'ttlMs' => 1000],
                ['name' => 'r2', 'ttlMs' => 2000],
                ['name' => 'r3', 'ttlMs' => 3000],
            ],
            'deadQueue' => 'dead',
        ]);

        $this->assertSame('retry', $decision->action);
        $this->assertSame('r1', $decision->retryQueue);
    }

    /**
     * @dataProvider retryCountProvider
     */
    public function testRetryQueueSelectionByRetryCount(int $attempts, string $expectedQueue): void
    {
        $decider = new RetryDecider();
        $decision = $decider->decide([
            'headers' => [
                'x-retry-count' => $attempts,
            ],
        ], [
            'maxAttempts' => 10,
            'retryQueues' => [
                ['name' => 'r1', 'ttlMs' => 1000],
                ['name' => 'r2', 'ttlMs' => 2000],
                ['name' => 'r3', 'ttlMs' => 3000],
            ],
            'deadQueue' => 'dead',
        ]);

        $this->assertSame('retry', $decision->action);
        $this->assertSame($expectedQueue, $decision->retryQueue);
    }

    public function testDecideUsesMaxAttempts(): void
    {
        $decider = new RetryDecider();
        $decision = $decider->decide([
            'headers' => [
                'x-retry-count' => 3,
            ],
        ], [
            'maxAttempts' => 3,
            'retryQueues' => [
                ['name' => 'r1', 'ttlMs' => 1000],
            ],
            'deadQueue' => 'dead',
        ]);

        $this->assertSame('dead', $decision->action);
        $this->assertSame('dead', $decision->retryQueue);
    }

    public function testAttemptsExceedRetryQueuesDeadWhenConfigured(): void
    {
        $decider = new RetryDecider();
        $decision = $decider->decide([
            'headers' => [
                'x-retry-count' => 5,
            ],
        ], [
            'maxAttempts' => 10,
            'retryQueues' => [
                ['name' => 'r1', 'ttlMs' => 1000],
                ['name' => 'r2', 'ttlMs' => 2000],
            ],
            'deadQueue' => 'dead',
        ]);

        $this->assertSame('dead', $decision->action);
        $this->assertSame('dead', $decision->retryQueue);
    }

    public function testAttemptsExceedRetryQueuesRejectWhenNoDeadQueue(): void
    {
        $decider = new RetryDecider();
        $decision = $decider->decide([
            'headers' => [
                'x-retry-count' => 5,
            ],
        ], [
            'maxAttempts' => 10,
            'retryQueues' => [
                ['name' => 'r1', 'ttlMs' => 1000],
                ['name' => 'r2', 'ttlMs' => 2000],
            ],
            'deadQueue' => null,
        ]);

        $this->assertSame('reject', $decision->action);
        $this->assertNull($decision->retryQueue);
    }

    public function testRejectsWhenRetryQueuesEmpty(): void
    {
        $decider = new RetryDecider();
        $decision = $decider->decide([
            'headers' => [
                'x-retry-count' => 0,
            ],
        ], [
            'maxAttempts' => 3,
            'retryQueues' => [],
            'deadQueue' => 'dead',
        ]);

        $this->assertSame('reject', $decision->action);
        $this->assertNull($decision->retryQueue);
    }

    /**
     * @dataProvider invalidRetryCountProvider
     */
    public function testInvalidRetryCountTreatsAsZero($value): void
    {
        $decider = new RetryDecider();
        $decision = $decider->decide([
            'headers' => [
                'x-retry-count' => $value,
            ],
        ], [
            'maxAttempts' => 10,
            'retryQueues' => [
                ['name' => 'r1', 'ttlMs' => 1000],
                ['name' => 'r2', 'ttlMs' => 2000],
            ],
            'deadQueue' => 'dead',
        ]);

        $this->assertSame('retry', $decision->action);
        $this->assertSame('r1', $decision->retryQueue);
    }

    public static function retryCountProvider(): array
    {
        return [
            'attempts-0' => [0, 'r1'],
            'attempts-1' => [1, 'r2'],
            'attempts-2' => [2, 'r3'],
        ];
    }

    public static function invalidRetryCountProvider(): array
    {
        return [
            'string' => ['1'],
            'negative' => [-1],
            'array' => [[1]],
        ];
    }
}
