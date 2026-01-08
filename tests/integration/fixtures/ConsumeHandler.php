<?php

namespace illusiard\rabbitmq\tests\integration\fixtures;

class ConsumeHandler
{
    public function __invoke(string $body, array $meta): bool
    {
        $logFile = getenv('HANDLER_LOG');
        if (is_string($logFile) && $logFile !== '') {
            file_put_contents($logFile, $body . PHP_EOL, FILE_APPEND);
        }

        $sleepMs = getenv('HANDLER_SLEEP_MS');
        if ($sleepMs !== false && is_numeric($sleepMs)) {
            usleep((int)$sleepMs * 1000);
        }

        return true;
    }
}
