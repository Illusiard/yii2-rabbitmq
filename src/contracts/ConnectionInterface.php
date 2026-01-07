<?php

namespace illusiard\rabbitmq\contracts;

interface ConnectionInterface
{
    public function connect(): void;

    public function isConnected(): bool;

    public function close(): void;

    public function getPublisher(): PublisherInterface;

    public function getConsumer(): ConsumerInterface;
}
