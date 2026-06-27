<?php

namespace Laravel\Cloud\Symfony\Tests;

use Laravel\Cloud\Symfony\Agent\AgentClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Exercises the real Guzzle-over-unix-socket path against a tiny local socket
 * server, proving the CURLOPT_UNIX_SOCKET_PATH mechanism actually talks HTTP.
 */
class AgentSocketIntegrationTest extends TestCase
{
    private ?Process $server = null;
    private string $socket = '';

    protected function setUp(): void
    {
        if (! \defined('CURLOPT_UNIX_SOCKET_PATH')) {
            $this->markTestSkipped('curl unix socket support is unavailable.');
        }

        if (! class_exists(Process::class)) {
            $this->markTestSkipped('symfony/process is not installed.');
        }

        $this->socket = sys_get_temp_dir().'/laravel-cloud-agent-'.bin2hex(random_bytes(4)).'.sock';

        $this->server = new Process(['php', __DIR__.'/Fixtures/agent-server.php', $this->socket, '2']);
        $this->server->start();

        // Wait for the server to bind the socket.
        $deadline = microtime(true) + 5;
        while (! file_exists($this->socket) && microtime(true) < $deadline) {
            usleep(20_000);
        }

        if (! file_exists($this->socket)) {
            $this->server->stop();
            $this->markTestSkipped('agent socket server did not start: '.$this->server->getErrorOutput());
        }
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        @unlink($this->socket);
    }

    public function testNextAndReportOverARealUnixSocket(): void
    {
        $agent = new AgentClient($this->socket);

        $data = $agent->next();

        $this->assertSame('integration-message', $data['messageId']);
        $this->assertSame('integration-receipt', $data['receiptHandle']);
        $this->assertSame('https://sqs/integration', $data['queueUrl']);

        // Reporting the outcome over the same socket must not raise.
        $agent->report('integration-message', 'integration-receipt', 'processed');

        $this->addToAssertionCount(1);
    }
}
