<?php

namespace illusiard\rabbitmq\rpc;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\exceptions\ErrorCode;
use illusiard\rabbitmq\exceptions\RabbitMqException;
use yii\base\InvalidConfigException;

class RpcAmqpConnectionResolver
{
    /**
     * @param RabbitMqService $service
     * @return object
     * @throws InvalidConfigException
     */
    public function resolve(RabbitMqService $service): object
    {
        $connection = $service->getConnection();
        if (!method_exists($connection, 'getAmqpConnection')) {
            throw new RabbitMqException('RPC requires an AMQP connection.', ErrorCode::CONNECTION_FAILED);
        }

        $amqpConnection = $connection->getAmqpConnection();
        if (
            !is_object($amqpConnection)
            || !method_exists($amqpConnection, 'isConnected')
            || !method_exists($amqpConnection, 'channel')
        ) {
            throw new RabbitMqException('RPC requires an AMQP connection.', ErrorCode::CONNECTION_FAILED);
        }

        if (!$amqpConnection->isConnected()) {
            throw new RabbitMqException('Dead connection.', ErrorCode::CONNECTION_FAILED);
        }

        return $amqpConnection;
    }
}
