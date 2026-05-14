<?php

namespace illusiard\rabbitmq\exceptions;

class ErrorCode
{
    public const GENERIC = 'GENERIC';
    public const CONFIG_INVALID = 'CONFIG_INVALID';
    public const CONNECTION_FAILED = 'CONNECTION_FAILED';
    public const CHANNEL_FAILED = 'CHANNEL_FAILED';
    public const PUBLISH_FAILED = 'PUBLISH_FAILED';
    public const PUBLISH_NACK = 'PUBLISH_NACK';
    public const PUBLISH_TIMEOUT = 'PUBLISH_TIMEOUT';
    public const PUBLISH_UNROUTABLE = 'PUBLISH_UNROUTABLE';
    public const PUBLISH_UNROUTABLE_UNCORRELATED = 'PUBLISH_UNROUTABLE_UNCORRELATED';
    public const CONSUME_FAILED = 'CONSUME_FAILED';
    public const HANDLER_FAILED = 'HANDLER_FAILED';
    public const SERIALIZATION_FAILED = 'SERIALIZATION_FAILED';
    public const MESSAGE_LIMIT_EXCEEDED = 'MESSAGE_LIMIT_EXCEEDED';
    public const RPC_TIMEOUT = 'RPC_TIMEOUT';
    public const TOPOLOGY_INVALID = 'TOPOLOGY_INVALID';
    public const DLQ_FAILED = 'DLQ_FAILED';
}
