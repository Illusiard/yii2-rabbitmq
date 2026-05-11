<?php

namespace illusiard\rabbitmq\tests\integration\fixtures;

use illusiard\rabbitmq\definitions\handler\HandlerInterface;
use illusiard\rabbitmq\message\Envelope;

class ConsumeHandler implements HandlerInterface
{
    public function handle(Envelope $envelope): bool
    {
        $logFile = getenv('HANDLER_LOG');
        if (is_string($logFile) && $logFile !== '') {
            file_put_contents($logFile, (string)$envelope->getPayload() . PHP_EOL, FILE_APPEND);
        }

        $sleepMs = getenv('HANDLER_SLEEP_MS');
        if ($sleepMs !== false && is_numeric($sleepMs)) {
            usleep((int)$sleepMs * 1000);
        }

        return true;
    }
}
