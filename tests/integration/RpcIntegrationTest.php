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
        $cmd = [
            PHP_BINARY,
            __DIR__ . '/fixtures/rpc_server.php',
        ];

        $env = array_merge(getenv(), [
            'RPC_QUEUE' => $queue,
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, null, $env);
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $ready = $this->waitForProcessOutput($pipes[1], 'READY', 5);
        $this->assertTrue($ready, 'RPC server did not become ready.');

        return [$process, $pipes];
    }

    private function stopProcess(array $handle): void
    {
        [$process, $pipes] = $handle;
        if (is_resource($process)) {
            proc_terminate($process);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    private function waitForProcessOutput($stream, string $needle, int $timeoutSec): bool
    {
        $deadline = microtime(true) + max(0, $timeoutSec);
        stream_set_blocking($stream, false);
        $buffer = '';

        while (microtime(true) < $deadline) {
            $chunk = stream_get_contents($stream);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
                if (strpos($buffer, $needle) !== false) {
                    return true;
                }
            }
            usleep(100000);
        }

        return false;
    }
}
