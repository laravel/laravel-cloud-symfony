<?php

namespace Laravel\Cloud\Symfony\Observability;

use RuntimeException;
use Throwable;

/**
 * Writes Laravel Cloud observability events to the in-container log socket.
 *
 * This is a framework-agnostic port of Laravel's
 * Illuminate\Foundation\Cloud\Events: it speaks the same wire protocol
 * (newline-delimited JSON over a persistent stream socket) so Managed Queues
 * running on Symfony surface the exact same queue metrics in the Laravel Cloud
 * dashboard as a Laravel app would.
 *
 * Every failure path is swallowed: observability must never take down a job.
 */
final class Events
{
    /**
     * The cloud socket.
     *
     * @var resource|null
     */
    private $socket = null;

    /**
     * Create a new instance.
     *
     * The address is a stream-socket address such as
     * "unix:///tmp/cloud-init.sock".
     */
    public function __construct(private readonly string $address)
    {
    }

    /**
     * Emit a single event.
     *
     * @param  array<string, mixed>  $payload
     */
    public function emit(array $payload): void
    {
        $this->emitMany([$payload]);
    }

    /**
     * Emit many events.
     *
     * @param  list<array<string, mixed>>  $payloads
     */
    public function emitMany(array $payloads): void
    {
        if ($payloads === []) {
            return;
        }

        try {
            $this->ensureConnected();

            $this->write($this->format($payloads));
        } catch (Throwable) {
            //
        }
    }

    /**
     * Write the payload to the socket.
     */
    private function write(string $payload): void
    {
        $originalPayloadLength = strlen($payload);
        $written = 0;
        $zeroLengthWrites = 0;

        while (true) {
            $thisWrite = @fwrite($this->socket, $payload);

            if ($thisWrite === false) {
                $e = new RuntimeException($this->withSocketMetaData('Unable to write to socket'));

                $this->disconnect();

                throw $e;
            }

            $written += $thisWrite;

            if ($written >= $originalPayloadLength) {
                return;
            }

            if ($thisWrite === 0) {
                $zeroLengthWrites++;
            }

            if ($zeroLengthWrites >= 5) {
                $e = new RuntimeException($this->withSocketMetaData('Unable to write bytes to socket'));

                $this->disconnect();

                throw $e;
            }

            $payload = substr($payload, $thisWrite);
        }
    }

    /**
     * Format the payloads as newline-delimited JSON.
     *
     * @param  list<array<string, mixed>>  $payloads
     *
     * @throws \JsonException
     */
    private function format(array $payloads): string
    {
        return array_reduce($payloads, function (string $carry, array $line) {
            if ($carry !== '') {
                $carry .= "\n";
            }

            return $carry .= json_encode($line, flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        }, '')."\n";
    }

    /**
     * Ensure the socket is connected.
     */
    private function ensureConnected(): void
    {
        if (! $this->connected()) {
            $this->connect();
        }
    }

    /**
     * Connect the socket.
     */
    private function connect(): void
    {
        $socket = stream_socket_client(
            address: $this->address,
            error_code: $errorCode,
            error_message: $errorMessage,
            timeout: 2,
            flags: STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT,
        );

        if ($socket === false) {
            throw new RuntimeException("Failed connecting to the socket: {$errorMessage} [{$errorCode}]");
        }

        if (! stream_set_timeout($socket, 2)) {
            $e = new RuntimeException($this->withSocketMetaData('Failed configuring socket timeout'));

            $this->disconnect();

            throw $e;
        }

        $this->socket = $socket;
    }

    /**
     * Determine if the socket is connected.
     */
    private function connected(): bool
    {
        if (gettype($this->socket) !== 'resource') {
            return false;
        }

        if (feof($this->socket)) {
            $this->disconnect();

            return false;
        }

        return true;
    }

    /**
     * Disconnect the socket.
     */
    private function disconnect(): void
    {
        if (gettype($this->socket) !== 'resource') {
            $this->socket = null;

            return;
        }

        try {
            fclose($this->socket);
        } catch (Throwable) {
            //
        }

        $this->socket = null;
    }

    /**
     * Decorate the message with the socket's meta data.
     */
    private function withSocketMetaData(string $message): string
    {
        $prefix = "{$message}\n---\n";

        if (! $this->connected()) {
            return "{$prefix}closed: true";
        }

        $meta = stream_get_meta_data($this->socket);

        return $prefix.array_reduce(array_keys($meta), function ($carry, $key) use ($meta) {
            try {
                return $carry.$key.': '.match ($meta[$key]) {
                    true => 'true',
                    false => 'false',
                    default => $meta[$key],
                }."\n";
            } catch (Throwable) {
                return $carry;
            }
        }, '');
    }
}
