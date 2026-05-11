<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\consume\RetryPolicyInterface;
use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;

class RecordingRetryPolicy implements RetryPolicyInterface
{
    public ?ConsumeResult $lastResult = null;

    public function apply(ConsumeResult $result, ConsumeContext $context): ConsumeResult
    {
        $this->lastResult = $result;

        return ConsumeResult::ack();
    }
}
