<?php

require __DIR__ . '/../bootstrap.php';

use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\rpc\RpcServer;

$queue = getenv('RPC_QUEUE');
if (!$queue) {
    fwrite(STDERR, "RPC_QUEUE is required\n");
    exit(1);
}

$readyFile = getenv('READY_LOCK');

$server = new RpcServer(Yii::$app->rabbitmq);
if ($readyFile) {
    $server->setReadyLockFile($readyFile);
}
$server->serve($queue, function (Envelope $req) {
    return new Envelope(['ok' => true], [], [], 'rpc.pong', $req->getCorrelationId());
});
