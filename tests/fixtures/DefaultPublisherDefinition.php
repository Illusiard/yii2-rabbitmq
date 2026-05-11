<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\definitions\publisher\AbstractPublisher;

class DefaultPublisherDefinition extends AbstractPublisher
{
    public function getExchange(): string
    {
        return 'exchange';
    }
}
