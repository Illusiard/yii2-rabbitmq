<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\topology\Topology;

class ConsoleTestRabbitMqService extends RabbitMqService
{
    public array $topology = [];
    public bool $pingResult = true;
    public ?string $lastError = null;
    public array $profiles = [];
    public bool $buildTopologyCalled = false;
    public bool $applyTopologyCalled = false;
    public ?Topology $buildTopologyReturn = null;
    public bool $lastDryRun = false;
    public bool $pingCalled = false;

    public function ping(int $timeout = 3): bool
    {
        $this->pingCalled = true;
        return $this->pingResult;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function forProfile(string $name): self
    {
        return $this;
    }

    public function buildTopology(): Topology
    {
        $this->buildTopologyCalled = true;
        if ($this->buildTopologyReturn instanceof Topology) {
            return $this->buildTopologyReturn;
        }

        return new Topology();
    }

    public function applyTopology(Topology $topology, bool $dryRun = false): void
    {
        $this->applyTopologyCalled = true;
        $this->lastDryRun = $dryRun;
    }
}
