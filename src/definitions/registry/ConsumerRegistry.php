<?php

namespace illusiard\rabbitmq\definitions\registry;

use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;

class ConsumerRegistry extends DefinitionRegistry
{
    protected string $interface = ConsumerInterface::class;
    protected string $suffix = 'Consumer';
    protected string $type = 'consumer';
}
