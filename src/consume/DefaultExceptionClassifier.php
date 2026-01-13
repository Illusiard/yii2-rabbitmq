<?php

namespace illusiard\rabbitmq\consume;

use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\exceptions\FatalException;
use illusiard\rabbitmq\exceptions\RecoverableException;

class DefaultExceptionClassifier implements ExceptionClassifierInterface
{
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

    public function classify(\Throwable $e, ConsumeContext $context): ConsumeResult
    {
        if ($e instanceof FatalException) {
            return ConsumeResult::stop();
        }

        if ($e instanceof RecoverableException) {
            return ConsumeResult::retry();
        }

        if ($this->matchesClass($e, $this->fatalExceptionClasses)) {
            return ConsumeResult::stop();
        }

        if ($this->matchesClass($e, $this->recoverableExceptionClasses)) {
            return ConsumeResult::retry();
        }

        return $this->consumeFailFast ? ConsumeResult::stop() : ConsumeResult::retry();
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
