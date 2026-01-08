<?php

require __DIR__ . '/../bootstrap.php';

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\console\ConsumeController;

$queue = getenv('CONSUME_QUEUE');
$handler = getenv('CONSUME_HANDLER');
$prefetch = getenv('CONSUME_PREFETCH') ? (int)getenv('CONSUME_PREFETCH') : 1;
$memoryLimit = getenv('CONSUME_MEMORY_MB') ? (int)getenv('CONSUME_MEMORY_MB') : 256;

if (!$queue || !$handler) {
    fwrite(STDERR, "CONSUME_QUEUE and CONSUME_HANDLER are required\n");
    exit(1);
}

$service = new RabbitMqService([
    'host' => getenv('RABBIT_HOST') ?: 'localhost',
    'port' => getenv('RABBIT_PORT') ? (int)getenv('RABBIT_PORT') : 5672,
    'user' => getenv('RABBIT_USER') ?: 'guest',
    'password' => getenv('RABBIT_PASSWORD') ?: 'guest',
    'vhost' => getenv('RABBIT_VHOST') ?: '/',
]);

if (Yii::$app) {
    Yii::$app->set('rabbitmq', $service);
}

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

$controller = new ConsumeController('rabbitmq/consume', Yii::$app);
$code = $controller->actionIndex($queue, $handler, $prefetch, $memoryLimit);
exit($code);
