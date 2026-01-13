<?php

require __DIR__ . '/../bootstrap.php';

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\console\ConsumeController;
use illusiard\rabbitmq\orchestration\RunnerOptions;

$consumerId = getenv('CONSUMER_ID');
$memoryLimit = getenv('CONSUME_MEMORY_MB') ? (int)getenv('CONSUME_MEMORY_MB') : 256;

if (!$consumerId) {
    fwrite(STDERR, "CONSUMER_ID is required\n");
    exit(1);
}

if (!getenv('CONSUME_QUEUE')) {
    fwrite(STDERR, "CONSUME_QUEUE is required\n");
    exit(1);
}

$readyFile = getenv('READY_LOCK');

$service = new RabbitMqService([
    'host' => getenv('RABBIT_HOST') ?: 'localhost',
    'port' => getenv('RABBIT_PORT') ? (int)getenv('RABBIT_PORT') : 5672,
    'user' => getenv('RABBIT_USER') ?: 'guest',
    'password' => getenv('RABBIT_PASSWORD') ?: 'guest',
    'vhost' => getenv('RABBIT_VHOST') ?: '/',
    'discovery' => [
        'enabled' => true,
        'paths' => ['@app/tests/integration/fixtures'],
    ],
]);

if (Yii::$app) {
    Yii::$app->set('rabbitmq', $service);
}

$controller = new ConsumeController('consume', Yii::$app);
$runnerOptions = null;
if ($readyFile) {
    $runnerOptions = new RunnerOptions($readyFile, null, $consumerId);
}
$controller->setRunnerOptions($runnerOptions);
$code = $controller->actionIndex($consumerId, $memoryLimit);
exit($code);
