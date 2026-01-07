<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\consume\ExceptionClassifier;
use illusiard\rabbitmq\exceptions\FatalException;
use illusiard\rabbitmq\exceptions\RecoverableException;
use illusiard\rabbitmq\middleware\ConsumePipeline;

class ConsumePipelineTest extends TestCase
{
    public function testRecoverableExceptionReturnsFalse(): void
    {
        $pipeline = new ConsumePipeline([], new ExceptionClassifier());

        $result = $pipeline->run(
            'body',
            ['properties' => []],
            ['queue' => 'test'],
            function () {
                throw new RecoverableException('retry');
            }
        );

        $this->assertFalse($result);
    }

    public function testFatalExceptionIsRethrown(): void
    {
        $pipeline = new ConsumePipeline([], new ExceptionClassifier());

        $this->expectException(FatalException::class);

        $pipeline->run(
            'body',
            ['properties' => []],
            ['queue' => 'test'],
            function () {
                throw new FatalException('fatal');
            }
        );
    }
}
