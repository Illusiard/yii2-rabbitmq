<?php

namespace illusiard\rabbitmq\topology;

class ExchangeDefinition
{
    private string $name;
    private string $type;
    private bool $durable;
    private bool $autoDelete;
    private bool $internal;
    private array $arguments;

    public function __construct(
        string $name,
        string $type = 'direct',
        bool $durable = true,
        bool $autoDelete = false,
        bool $internal = false,
        array $arguments = []
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->durable = $durable;
        $this->autoDelete = $autoDelete;
        $this->internal = $internal;
        $this->arguments = $arguments;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isDurable(): bool
    {
        return $this->durable;
    }

    public function isAutoDelete(): bool
    {
        return $this->autoDelete;
    }

    public function isInternal(): bool
    {
        return $this->internal;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }
}
