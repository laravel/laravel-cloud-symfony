<?php

namespace Laravel\Cloud\Symfony\Queue\Retry;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Fetches the failed jobs behind a dashboard-retry URL, as Laravel's
 * Cloud\FailedJobProvider::find() does.
 */
final class FailedJobFetcher
{
    /**
     * Laravel's policy: 10 attempts, exponential backoff, no retry on a 403.
     */
    private const ATTEMPTS = 10;

    private readonly \Closure $sleep;

    public function __construct(
        #[\SensitiveParameter] private readonly ?string $secret,
        private ?ClientInterface $http = null,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static fn (int $milliseconds) => usleep($milliseconds * 1000);
    }

    /**
     * @return \Generator<int, array{id: string, queue: string, payload: string}>
     */
    public function fetch(string $url): \Generator
    {
        // Built lazily so a missing APP_SECRET fails the retry, not the container.
        $decrypter = new PayloadDecrypter($this->secret);

        $next = $url;

        while ($next !== null) {
            [$jobs, $next] = $this->page($next, $decrypter);

            yield from $jobs;
        }
    }

    /**
     * @return array{0: list<array{id: string, queue: string, payload: string}>, 1: ?string}
     */
    private function page(string $url, PayloadDecrypter $decrypter): array
    {
        $response = $this->request($url, $decrypter);

        $payload = json_decode($decrypter->decrypt((string) $response->getBody()), true);

        if (! is_array($payload)) {
            throw new RuntimeException('Laravel Cloud returned a failed job payload that is not valid JSON.');
        }

        return match ($version = $response->getHeaderLine('Cloud-Payload-Version')) {
            '1' => [
                array_map($this->job(...), is_array($payload['data'] ?? null) ? array_values($payload['data']) : []),
                is_string($payload['links']['next'] ?? null) ? $payload['links']['next'] : null,
            ],
            '' => [[$this->job($payload)], null],
            default => throw new RuntimeException("Unsupported Laravel Cloud failed job payload version: {$version}."),
        };
    }

    private function request(string $url, PayloadDecrypter $decrypter): ResponseInterface
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->client()->request('GET', $url, [
                    'connect_timeout' => 10,
                    'timeout' => 10,
                    'http_errors' => false,
                    'headers' => [
                        'Cloud-Payload-Version' => '1',
                        'Cloud-Encryption-Cipher' => $decrypter->cipherHeader(),
                    ],
                ]);

                $status = $response->getStatusCode();

                if ($status >= 200 && $status < 300) {
                    return $response;
                }

                $error = new RuntimeException("Laravel Cloud returned HTTP {$status} for the failed job URL.");

                if ($status === 403) {
                    throw $error;
                }
            } catch (GuzzleException $e) {
                $error = new RuntimeException('Unable to reach Laravel Cloud for the failed job URL: '.$e->getMessage(), previous: $e);
            }

            if ($attempt >= self::ATTEMPTS) {
                throw $error;
            }

            ($this->sleep)(500 * (2 ** ($attempt - 1)));
        }
    }

    /**
     * @return array{id: string, queue: string, payload: string}
     */
    private function job(mixed $job): array
    {
        if (! is_array($job) || ! is_string($job['id'] ?? null) || ! is_string($job['payload'] ?? null)) {
            throw new RuntimeException('Laravel Cloud returned a failed job without an id or payload.');
        }

        return [
            'id' => $job['id'],
            'queue' => is_string($job['queue'] ?? null) ? $job['queue'] : '',
            'payload' => $job['payload'],
        ];
    }

    private function client(): ClientInterface
    {
        return $this->http ??= new Client();
    }
}
