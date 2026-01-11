<?php

namespace illusiard\rabbitmq\tests\integration;

use illusiard\rabbitmq\rpc\RpcClient;
use illusiard\rabbitmq\rpc\RpcTimeoutException;
use illusiard\rabbitmq\message\Envelope;

/**
 * @group integration
 */
class RpcIntegrationTest extends IntegrationTestCase
{
    public function testAMQP_RPC_01_requestReply(): void
    {
        $queue = $this->uniqueName('rpc_q');
        $this->declareQueue($queue);

        $process = $this->startRpcServer($queue);

        try {
            $client = new RpcClient($this->service);
            $request = new Envelope(['ping' => true], [], [], 'rpc.ping', 'corr_' . uniqid('', true));
            $response = $client->call($request, '', $queue, 5);

            $this->assertSame($request->getCorrelationId(), $response->getCorrelationId());
            $this->assertSame(true, $response->getPayload()['ok'] ?? null);
        } finally {
            $this->stopProcess($process);
        }
    }

    public function testAMQP_RPC_02_timeout(): void
    {
        $queue = $this->uniqueName('rpc_q');
        $this->declareQueue($queue);

        $client = new RpcClient($this->service);
        $request = new Envelope(['ping' => true], [], [], 'rpc.ping', 'corr_' . uniqid('', true));

        $this->expectException(RpcTimeoutException::class);
        $client->call($request, '', $queue, 1);
    }

    private function startRpcServer(string $queue)
    {
        $readyFile = sys_get_temp_dir() . '/rpc_ready_' . uniqid('', true) . '.txt';
        @unlink($readyFile);

        $cmd = [
            PHP_BINARY,
            __DIR__ . '/fixtures/rpc_server.php',
        ];

        $env = array_merge(getenv(), [
            'RPC_QUEUE' => $queue,
            'RPC_READY_FILE' => $readyFile,
        ]);

        $null = (stripos(PHP_OS_FAMILY, 'Windows') !== false) ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $null, 'w'],
            2 => ['file', $null, 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, null, $env);
        $this->assertIsResource($process);

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $ready = $this->waitForReadyFile($readyFile, 10);
        $this->assertTrue($ready, 'RPC server did not become ready.');

        return [$process, $readyFile];
    }

    private function waitForReadyFile(string $path, int $timeoutSec): bool
    {
        $deadline = microtime(true) + max(0, $timeoutSec);

        while (microtime(true) < $deadline) {
            if (is_file($path)) {
                $data = @file_get_contents($path);
                if (is_string($data) && str_contains($data, 'READY')) {
                    return true;
                }
            }
            usleep(10_000); // 10ms
        }

        return false;
    }

    private function stopProcess(array $handle): void
    {
        [$process, $readyFile] = $handle;

        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }

        if (is_string($readyFile) && $readyFile !== '') {
            @unlink($readyFile);
        }
    }
}
