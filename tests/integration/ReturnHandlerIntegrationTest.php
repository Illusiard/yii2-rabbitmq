<?php

namespace illusiard\rabbitmq\tests\integration;

use illusiard\rabbitmq\contracts\ReturnHandlerInterface;
use illusiard\rabbitmq\amqp\ReturnedMessage;

/**
 * @group integration
 */
class ReturnHandlerIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestReturnHandler::reset();

        $service = $this->createService([
            'mandatory' => true,
            'confirm' => false,
            'returnHandler' => TestReturnHandler::class,
            'returnHandlerEnabled' => true,
        ]);
        $this->setService($service);
    }

    public function testMandatoryReturnInvokesHandler(): void
    {
        $exchange = $this->uniqueName('return-ex');
        $routingKey = 'no.route';

        $this->declareExchange($exchange, 'direct', true);

        $this->service->publish('payload', $exchange, $routingKey);

        $deadline = microtime(true) + 2;
        while (microtime(true) < $deadline && TestReturnHandler::count() === 0) {
            $this->service->tick(0.1);
            usleep(100000);
        }

        $this->assertGreaterThan(0, TestReturnHandler::count());
        $event = TestReturnHandler::$events[0];

        $this->assertSame($exchange, $event->exchange);
        $this->assertSame($routingKey, $event->routingKey);
        $this->assertGreaterThan(0, $event->replyCode);
        $this->assertNotSame('', $event->replyText);
        $this->assertGreaterThan(0, $event->bodySize);
    }
}

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
