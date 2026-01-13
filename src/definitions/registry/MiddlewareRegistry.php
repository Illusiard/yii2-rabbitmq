<?php

namespace illusiard\rabbitmq\definitions\registry;

use illusiard\rabbitmq\definitions\middleware\MiddlewareInterface;

class MiddlewareRegistry extends DefinitionRegistry
{
    protected string $interface = MiddlewareInterface::class;
    protected string $suffix = 'Middleware';
    protected string $type = 'middleware';
}
