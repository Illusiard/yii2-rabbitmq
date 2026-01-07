<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\consume\ExceptionClassifier;
use illusiard\rabbitmq\exceptions\FatalException;
use illusiard\rabbitmq\exceptions\RecoverableException;

class ExceptionClassifierTest extends TestCase
{
    public function testFatalExceptionInstance(): void
    {
        $classifier = new ExceptionClassifier();

        $this->assertSame(
            ExceptionClassifier::RESULT_FATAL,
            $classifier->classify(new FatalException('boom'))
        );
    }

    public function testRecoverableExceptionInstance(): void
    {
        $classifier = new ExceptionClassifier();

        $this->assertSame(
            ExceptionClassifier::RESULT_RECOVERABLE,
            $classifier->classify(new RecoverableException('retry'))
        );
    }

    public function testClassInFatalList(): void
    {
        $classifier = new ExceptionClassifier(true, [\RuntimeException::class], []);

        $this->assertSame(
            ExceptionClassifier::RESULT_FATAL,
            $classifier->classify(new \RuntimeException('fatal'))
        );
    }

    public function testClassInRecoverableList(): void
    {
        $classifier = new ExceptionClassifier(true, [], [\RuntimeException::class]);

        $this->assertSame(
            ExceptionClassifier::RESULT_RECOVERABLE,
            $classifier->classify(new \RuntimeException('recover'))
        );
    }

    public function testDefaultConsumeFailFastTrue(): void
    {
        $classifier = new ExceptionClassifier(true);

        $this->assertSame(
            ExceptionClassifier::RESULT_FATAL,
            $classifier->classify(new \RuntimeException('default fatal'))
        );
    }

    public function testDefaultConsumeFailFastFalse(): void
    {
        $classifier = new ExceptionClassifier(false);

        $this->assertSame(
            ExceptionClassifier::RESULT_RECOVERABLE,
            $classifier->classify(new \RuntimeException('default recoverable'))
        );
    }
}
