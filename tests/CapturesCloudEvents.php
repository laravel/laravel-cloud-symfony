<?php

namespace Laravel\Cloud\Symfony\Tests;

/**
 * Captures emitted Cloud events over a real local unix socket.
 */
trait CapturesCloudEvents
{
    private string $eventSocketPath = '';

    /** @var resource|null */
    private $eventServer = null;

    private function openEventSocket(): void
    {
        $this->eventSocketPath = sys_get_temp_dir().'/laravel-cloud-events-'.bin2hex(random_bytes(4)).'.sock';

        $server = @stream_socket_server('unix://'.$this->eventSocketPath, $errno, $errstr);

        if ($server === false) {
            $this->markTestSkipped("Unable to bind a unix socket: {$errstr} ({$errno})");
        }

        $this->eventServer = $server;
    }

    private function closeEventSocket(): void
    {
        if (is_resource($this->eventServer)) {
            fclose($this->eventServer);
        }

        @unlink($this->eventSocketPath);
    }

    private function eventSocketAddress(): string
    {
        return 'unix://'.$this->eventSocketPath;
    }

    /**
     * Emits in a forked child while this process reads: a full failed_job event
     * outgrows the socket buffer (8 KiB on macOS) and would block the writer.
     *
     * @return list<array<string, mixed>>
     */
    private function capture(callable $emit): array
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Capturing Cloud events needs the pcntl extension.');
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Unable to fork to capture Cloud events.');
        }

        if ($pid === 0) {
            $emit();

            // Skip PHPUnit's shutdown; closing the socket ends the read.
            posix_kill(posix_getpid(), SIGKILL);
        }

        $buffer = '';
        $client = @stream_socket_accept($this->eventServer, 2);

        if ($client !== false) {
            stream_set_timeout($client, 5);

            while (! feof($client)) {
                $chunk = fread($client, 65536);

                if ($chunk === false || ($chunk === '' && stream_get_meta_data($client)['timed_out'])) {
                    break;
                }

                $buffer .= $chunk;
            }

            fclose($client);
        }

        pcntl_waitpid($pid, $status);

        $lines = [];

        foreach (explode("\n", trim($buffer)) as $line) {
            if ($line !== '') {
                $lines[] = json_decode($line, true);
            }
        }

        return $lines;
    }
}
