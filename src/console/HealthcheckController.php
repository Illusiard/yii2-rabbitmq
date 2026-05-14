<?php

namespace illusiard\rabbitmq\console;

use JsonException;
use Throwable;
use yii\base\InvalidConfigException;

class HealthcheckController extends BaseRabbitMqController
{
    public string $profile = 'default';
    public int $timeout = 3;
    public int $json = 0;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['profile', 'timeout', 'json']);
    }

    /**
     * @return int
     * @throws InvalidConfigException
     * @throws JsonException
     */
    public function actionIndex(): int
    {
        $rabbit = $this->getRabbitService();

        try {
            if (!empty($rabbit->profiles)) {
                $rabbit = $rabbit->forProfile($this->profile);
            }
        } catch (Throwable $e) {
            return $this->renderResult(false, $this->exceptionMessage($e));
        }

        $ok = $rabbit->ping($this->timeout);
        $error = $ok ? null : ($rabbit->getLastError() ?? 'Unknown error');

        return $this->renderResult($ok, $error);
    }

    /**
     * @param bool $ok
     * @param ?string $error
     * @return int
     * @throws JsonException
     */
    private function renderResult(bool $ok, ?string $error): int
    {
        if ($this->json) {
            $payload = [
                'ok' => $ok,
                'error' => $error,
            ];
            $this->stdout(json_encode($payload, JSON_THROW_ON_ERROR) . PHP_EOL);
        } else if ($ok) {
            $this->stdout("OK\n");
        } else {
            $this->stderr("FAIL: " . $error . PHP_EOL);
        }

        return $ok ? 0 : 1;
    }

}
