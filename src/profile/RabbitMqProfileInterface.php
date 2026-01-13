<?php

namespace illusiard\rabbitmq\profile;

interface RabbitMqProfileInterface
{
    public function getConsumerDefaults(): array;

    public function getPublisherDefaults(): array;
}
