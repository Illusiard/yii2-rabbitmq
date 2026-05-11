<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\definitions\publisher\AbstractPublisher;

class TestPublisherDefinition extends AbstractPublisher
{
    public function getExchange(): string
    {
        return 'exchange';
    }

    public function getOptions(): array
    {
        return [
            'confirm' => true,
        ];
    }

    public function getMiddlewares(): array
    {
        return ['publish-extra'];
    }
}
