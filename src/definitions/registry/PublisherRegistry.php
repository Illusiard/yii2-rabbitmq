<?php

namespace illusiard\rabbitmq\definitions\registry;

use illusiard\rabbitmq\definitions\publisher\PublisherInterface;

class PublisherRegistry extends DefinitionRegistry
{
    protected string $interface = PublisherInterface::class;
    protected string $suffix = 'Publisher';
    protected string $type = 'publisher';
}
