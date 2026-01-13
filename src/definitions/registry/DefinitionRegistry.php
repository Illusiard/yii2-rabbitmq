<?php

namespace illusiard\rabbitmq\definitions\registry;

use illusiard\rabbitmq\definitions\DefinitionIdDeriver;
use illusiard\rabbitmq\exceptions\DuplicateDefinitionIdException;
use InvalidArgumentException;

abstract class DefinitionRegistry
{
    protected array $items = [];
    protected string $interface;
    protected string $suffix;
    protected string $type;

    public function __construct(array $items = [])
    {
        foreach ($items as $id => $fqcn) {
            if (is_string($id) && is_string($fqcn)) {
                $this->items[$id] = $fqcn;
            }
        }
    }

    public function register(string $fqcn): void
    {
        if (!class_exists($fqcn)) {
            throw new InvalidArgumentException("Class '{$fqcn}' not found.");
        }

        if (!is_subclass_of($fqcn, $this->interface)) {
            throw new InvalidArgumentException("Class '{$fqcn}' must implement {$this->interface}.");
        }

        $id = DefinitionIdDeriver::derive($fqcn, $this->suffix);
        if (isset($this->items[$id])) {
            throw new DuplicateDefinitionIdException(
                "Duplicate {$this->type} id '{$id}' for classes '{$this->items[$id]}' and '{$fqcn}'."
            );
        }

        $this->items[$id] = $fqcn;
    }

    public function get(string $id): ?string
    {
        return $this->items[$id] ?? null;
    }

    public function all(): array
    {
        return $this->items;
    }
}
