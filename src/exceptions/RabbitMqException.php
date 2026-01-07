<?php

namespace illusiard\rabbitmq\exceptions;

use RuntimeException;

class RabbitMqException extends RuntimeException
{
    protected string $errorCode;

    public function __construct(string $message = '', string $errorCode = ErrorCode::GENERIC, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
