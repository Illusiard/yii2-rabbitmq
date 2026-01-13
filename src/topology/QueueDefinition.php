<?php

namespace illusiard\rabbitmq\topology;

class QueueDefinition
{
    private string $name;
    private bool $durable;
    private bool $autoDelete;
    private bool $exclusive;
    private array $arguments;

    public function __construct(
        string $name,
        bool $durable = true,
        bool $autoDelete = false,
        bool $exclusive = false,
        array $arguments = []
    ) {
        $this->name = $name;
        $this->durable = $durable;
        $this->autoDelete = $autoDelete;
        $this->exclusive = $exclusive;
        $this->arguments = $arguments;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isDurable(): bool
    {
        return $this->durable;
    }

    public function isAutoDelete(): bool
    {
        return $this->autoDelete;
    }

    public function isExclusive(): bool
    {
        return $this->exclusive;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }
}
