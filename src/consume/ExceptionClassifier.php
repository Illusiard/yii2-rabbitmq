<?php

namespace illusiard\rabbitmq\consume;

use illusiard\rabbitmq\exceptions\FatalException;
use illusiard\rabbitmq\exceptions\RecoverableException;

class ExceptionClassifier
{
    public const RESULT_FATAL = 'fatal';
    public const RESULT_RECOVERABLE = 'recoverable';

    private bool $consumeFailFast;
    private array $fatalExceptionClasses;
    private array $recoverableExceptionClasses;

    public function __construct(
        bool $consumeFailFast = true,
        array $fatalExceptionClasses = [],
        array $recoverableExceptionClasses = []
    ) {
        $this->consumeFailFast = $consumeFailFast;
        $this->fatalExceptionClasses = $fatalExceptionClasses;
        $this->recoverableExceptionClasses = $recoverableExceptionClasses;
    }

    public function classify(\Throwable $e): string
    {
        if ($e instanceof FatalException) {
            return self::RESULT_FATAL;
        }

        if ($e instanceof RecoverableException) {
            return self::RESULT_RECOVERABLE;
        }

        if ($this->matchesClass($e, $this->fatalExceptionClasses)) {
            return self::RESULT_FATAL;
        }

        if ($this->matchesClass($e, $this->recoverableExceptionClasses)) {
            return self::RESULT_RECOVERABLE;
        }

        return $this->consumeFailFast ? self::RESULT_FATAL : self::RESULT_RECOVERABLE;
    }

    private function matchesClass(\Throwable $e, array $classes): bool
    {
        foreach ($classes as $class) {
            if (is_string($class) && $class !== '' && is_a($e, $class, true)) {
                return true;
            }
        }

        return false;
    }
}
