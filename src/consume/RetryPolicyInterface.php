<?php

namespace illusiard\rabbitmq\consume;

use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;

interface RetryPolicyInterface
{
    public function apply(ConsumeResult $result, ConsumeContext $context): ConsumeResult;
}
