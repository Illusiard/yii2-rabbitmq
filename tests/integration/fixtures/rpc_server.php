<?php

require __DIR__ . '/../bootstrap.php';

use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\rpc\RpcServer;

$queue = getenv('RPC_QUEUE');
if (!$queue) {
    fwrite(STDERR, "RPC_QUEUE is required\n");
    exit(1);
}

$readyFile = getenv('RPC_READY_FILE');

$server = new RpcServer(Yii::$app->rabbitmq);
$server->setOnStart(function () use ($readyFile) {
    if ($readyFile) {
        @file_put_contents($readyFile, "READY\n", LOCK_EX);
    }
});
$server->serve($queue, function (Envelope $req) {
    return new Envelope(['ok' => true], [], [], 'rpc.pong', $req->getCorrelationId());
});