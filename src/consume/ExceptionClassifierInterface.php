<?php

namespace illusiard\rabbitmq\consume;

use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;

interface ExceptionClassifierInterface
{
    public function classify(\Throwable $e, ConsumeContext $context): ConsumeResult;
}
