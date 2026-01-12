<?php

namespace illusiard\rabbitmq\consumer;

interface ConsumerInterface
{
    public function queue(): string;

    public function handler();

    public function options(): array;
}
