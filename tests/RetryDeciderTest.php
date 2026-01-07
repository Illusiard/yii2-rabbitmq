<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\retry\RetryDecider;

class RetryDeciderTest extends TestCase
{
    public function testDecideUsesXDeath(): void
    {
        $decider = new RetryDecider();
        $decision = $decider->decide([
            'headers' => [
                'x-death' => [
                    ['count' => 1],
                    ['count' => 2],
                ],
            ],
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
        $this->assertSame('r3', $decision->retryQueue);
    }

    public function testDecideUsesMaxAttempts(): void
    {
        $decider = new RetryDecider();
        $decision = $decider->decide([
            'headers' => [
                'x-attempt' => 3,
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

    public function testDecideRejectsWhenNoQueue(): void
    {
        $decider = new RetryDecider();
        $decision = $decider->decide([
            'headers' => [],
        ], [
            'maxAttempts' => 2,
            'retryQueues' => [],
            'deadQueue' => null,
        ]);

        $this->assertSame('reject', $decision->action);
        $this->assertNull($decision->retryQueue);
    }
}
