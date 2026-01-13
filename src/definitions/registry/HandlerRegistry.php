<?php

namespace illusiard\rabbitmq\definitions\registry;

use illusiard\rabbitmq\definitions\handler\HandlerInterface;

class HandlerRegistry extends DefinitionRegistry
{
    protected string $interface = HandlerInterface::class;
    protected string $suffix = 'Handler';
    protected string $type = 'handler';
}
