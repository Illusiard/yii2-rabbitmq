<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\amqp\AmqpConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class TestAmqpConnection extends AmqpConnection
{
    private AMQPStreamConnection $amqp;

    public function __construct(AMQPStreamConnection $amqp)
    {
        $this->amqp = $amqp;
        parent::__construct([
            'host' => 'localhost',
            'port' => 5672,
            'user' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'connectionTimeout' => 1,
            'readWriteTimeout' => 1,
            'heartbeat' => 30,
        ]);
    }

    public function getAmqpConnection(): AMQPStreamConnection
    {
        return $this->amqp;
    }
}
