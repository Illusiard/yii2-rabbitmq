<?php

namespace illusiard\rabbitmq\definitions\consumer;

interface ConsumerInterface
{
    public function getQueue(): string;

    public function getHandler();

    public function getOptions(): array;

    public function getMiddlewares(): array;
}
