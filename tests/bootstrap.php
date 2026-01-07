<?php

require __DIR__ . '/../vendor/autoload.php';

define('YII_ENV', 'test');
define('YII_DEBUG', true);

require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

new yii\console\Application(require __DIR__ . '/config/console.php');
