<?php

namespace illusiard\rabbitmq\rpc;

use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\ErrorCode;

class RpcTimeoutException extends RabbitMqException
{
    public function __construct(string $message = 'RPC timeout', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, ErrorCode::RPC_TIMEOUT, $code, $previous);
    }
}
