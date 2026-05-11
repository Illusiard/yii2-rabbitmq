<?php

namespace illusiard\rabbitmq\tests\fixtures;

class ConsoleTestDlqService
{
    private array $items;

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public function inspect(string $queue, int $limit = 10, bool $acknowledge = false): array
    {
        return $this->items;
    }
}
