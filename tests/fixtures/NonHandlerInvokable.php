<?php

namespace illusiard\rabbitmq\tests\fixtures;

class NonHandlerInvokable
{
    public function __invoke(): bool
    {
        return true;
    }
}
