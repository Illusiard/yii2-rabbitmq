<?php

namespace illusiard\rabbitmq\consumer;

use illusiard\rabbitmq\exceptions\DuplicateConsumerIdException;

class ConsumerRegistry
{
    private array $consumers = [];

    public function __construct(array $consumers = [])
    {
        foreach ($consumers as $id => $fqcn) {
            $this->consumers[$id] = $fqcn;
        }
    }

    public function register(string $fqcn): void
    {
        $id = ConsumerIdDeriver::derive($fqcn);
        if (isset($this->consumers[$id])) {
            throw new DuplicateConsumerIdException(
                "Duplicate consumer id '{$id}' for classes '{$this->consumers[$id]}' and '{$fqcn}'."
            );
        }

        $this->consumers[$id] = $fqcn;
    }

    public function get(string $id): ?string
    {
        return $this->consumers[$id] ?? null;
    }

    public function all(): array
    {
        return $this->consumers;
    }
}
