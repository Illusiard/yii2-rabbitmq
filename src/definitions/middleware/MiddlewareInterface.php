<?php

namespace illusiard\rabbitmq\definitions\middleware;

use illusiard\rabbitmq\definitions\consume\ConsumeContext;

interface MiddlewareInterface
{
    public function process(ConsumeContext $context, callable $next);
}
