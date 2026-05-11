<?php

namespace illusiard\rabbitmq\tests\integration;

use illusiard\rabbitmq\rpc\RpcClient;
use illusiard\rabbitmq\rpc\RpcTimeoutException;
use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\helpers\ProcessHelper;

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
            'READY_LOCK' => $readyFile,
        ]);

        $null = (stripos(PHP_OS_FAMILY, 'Windows') !== false) ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $null, 'w'],
            2 => ['file', $null, 'w'],
        ];

        [$process, $pipes] = ProcessHelper::startProcess($cmd, $env, $descriptors);
        $this->assertIsResource($process);

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $ready = $this->waitForFileExists($readyFile, 10);
        $this->assertTrue($ready, 'RPC server did not become ready.');

        return [$process, $pipes, $readyFile];
    }

    private function stopProcess(array $handle): void
    {
        [$process, $pipes, $readyFile] = $handle;

        ProcessHelper::terminateProcess($process);
        ProcessHelper::closePipes($pipes);

        if (is_string($readyFile) && $readyFile !== '') {
            $this->waitForFileMissing($readyFile, 5);
            @unlink($readyFile);
        }
    }
}
