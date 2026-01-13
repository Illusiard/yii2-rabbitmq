<?php

namespace illusiard\rabbitmq\orchestration;

class RunnerOptions
{
    public ?string $lockFilePath;
    public ?string $lockFileDir;
    public ?string $consumerId;
    public bool $createLockOnStart;
    public bool $removeLockOnStop;

    public function __construct(
        ?string $lockFilePath = null,
        ?string $lockFileDir = null,
        ?string $consumerId = null,
        bool $createLockOnStart = true,
        bool $removeLockOnStop = true
    ) {
        $this->lockFilePath = $lockFilePath;
        $this->lockFileDir = $lockFileDir;
        $this->consumerId = $consumerId;
        $this->createLockOnStart = $createLockOnStart;
        $this->removeLockOnStop = $removeLockOnStop;
    }
}
