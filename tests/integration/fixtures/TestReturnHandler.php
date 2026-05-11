<?php

namespace illusiard\rabbitmq\tests\integration\fixtures;

use illusiard\rabbitmq\amqp\ReturnedMessage;
use illusiard\rabbitmq\contracts\ReturnHandlerInterface;

class TestReturnHandler implements ReturnHandlerInterface
{
    /** @var ReturnedMessage[] */
    public static array $events = [];

    public function handle(ReturnedMessage $event): void
    {
        self::$events[] = $event;
    }

    public static function reset(): void
    {
        self::$events = [];
    }

    public static function count(): int
    {
        return count(self::$events);
    }
}
