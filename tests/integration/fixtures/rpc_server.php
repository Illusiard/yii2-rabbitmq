<?php

require __DIR__ . '/../bootstrap.php';

use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\rpc\RpcServer;

$queue = getenv('RPC_QUEUE');
if (!$queue) {
    fwrite(STDERR, "RPC_QUEUE is required\n");
    exit(1);
}

$server = new RpcServer(Yii::createObject($config['components']['rabbitmq']));
$server->setOnStart(function () {
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
});
//$server->serve($queue, RpcHandler::class);
$server->serve($queue, function (Envelope $req) {
    // ответ с ok=true и тем же correlationId сохранится RpcServer-ом
    return new Envelope(['ok' => true], [], [], 'rpc.pong', $req->getCorrelationId());
});