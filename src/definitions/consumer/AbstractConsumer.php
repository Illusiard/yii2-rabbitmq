<?php

namespace illusiard\rabbitmq\definitions\consumer;

abstract class AbstractConsumer implements ConsumerInterface
{
    public function getOptions(): array
    {
        return [];
    }

    public function getMiddlewares(): array
    {
        return [];
    }
}
