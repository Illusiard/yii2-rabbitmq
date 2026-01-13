<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\profile\OptionsMerger;
use PHPUnit\Framework\TestCase;

class OptionsMergerTest extends TestCase
{
    public function testScalarOverrides(): void
    {
        $defaults = [
            'prefetch' => 1,
            'enabled' => true,
        ];
        $overrides = [
            'prefetch' => 5,
            'enabled' => false,
        ];

        $this->assertSame([
            'prefetch' => 5,
            'enabled' => false,
        ], OptionsMerger::merge($defaults, $overrides));
    }

    public function testAssociativeRecursiveMerge(): void
    {
        $defaults = [
            'retryPolicy' => [
                'maxAttempts' => 3,
                'delaySeconds' => 5,
            ],
        ];
        $overrides = [
            'retryPolicy' => [
                'delaySeconds' => 10,
            ],
        ];

        $this->assertSame([
            'retryPolicy' => [
                'maxAttempts' => 3,
                'delaySeconds' => 10,
            ],
        ], OptionsMerger::merge($defaults, $overrides));
    }

    public function testListMergeKeepsUniqueOrder(): void
    {
        $defaults = [
            'middlewares' => ['a', 'b'],
        ];
        $overrides = [
            'middlewares' => ['b', 'c'],
        ];

        $this->assertSame([
            'middlewares' => ['a', 'b', 'c'],
        ], OptionsMerger::merge($defaults, $overrides));
    }

    public function testTopLevelListMergeKeepsUniqueOrder(): void
    {
        $defaults = ['a', 'b'];
        $overrides = ['b', 'c'];

        $this->assertSame(['a', 'b', 'c'], OptionsMerger::merge($defaults, $overrides));
    }

    public function testNullOverrideRemovesKey(): void
    {
        $defaults = [
            'retryPolicy' => [
                'maxAttempts' => 3,
                'delaySeconds' => 5,
            ],
            'prefetch' => 1,
        ];
        $overrides = [
            'retryPolicy' => [
                'delaySeconds' => null,
            ],
            'prefetch' => null,
        ];

        $this->assertSame([
            'retryPolicy' => [
                'maxAttempts' => 3,
            ],
        ], OptionsMerger::merge($defaults, $overrides));
    }
}
