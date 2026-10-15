<?php

namespace Laravel\Cloud\Symfony\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Laravel\Cloud\Symfony\Queue\Retry\FailedJobFetcher;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

class FailedJobFetcherTest extends TestCase
{
    use EncryptsLikeLaravel;

    private const SECRET = '0123456789abcdef0123456789abcdef';

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    /** @var list<int> */
    private array $sleeps = [];

    public function testItFollowsNextLinksAcrossPages(): void
    {
        $fetcher = $this->fetcher([
            $this->page([$this->job('job-1'), $this->job('job-2')], next: 'https://cloud.test/failed?page=2'),
            $this->page([$this->job('job-3')], next: null),
        ]);

        $jobs = iterator_to_array($fetcher->fetch('https://cloud.test/failed?signature=abc'), false);

        $this->assertSame(['job-1', 'job-2', 'job-3'], array_column($jobs, 'id'));
        $this->assertSame('emails', $jobs[0]['queue']);
        $this->assertSame('{"body":"job-1"}', $jobs[0]['payload']);

        $this->assertCount(2, $this->history);
        $this->assertSame('https://cloud.test/failed?signature=abc', (string) $this->history[0]['request']->getUri());
        $this->assertSame('https://cloud.test/failed?page=2', (string) $this->history[1]['request']->getUri());
    }

    public function testItAsksForTheVersionedPayloadAndItsCipher(): void
    {
        $fetcher = $this->fetcher([$this->page([$this->job('job-1')], next: null)]);

        iterator_to_array($fetcher->fetch('https://cloud.test/failed'));

        $request = $this->history[0]['request'];
        $this->assertSame('1', $request->getHeaderLine('Cloud-Payload-Version'));
        $this->assertSame('AES-256-CBC', $request->getHeaderLine('Cloud-Encryption-Cipher'));
    }

    public function testAnUnversionedResponseIsASingleJob(): void
    {
        $fetcher = $this->fetcher([
            new Response(200, [], self::encryptLikeLaravel(json_encode($this->job('job-1')), self::SECRET)),
        ]);

        $jobs = iterator_to_array($fetcher->fetch('https://cloud.test/failed'), false);

        $this->assertSame(['job-1'], array_column($jobs, 'id'));
    }

    public function testItRetriesTransientFailuresWithExponentialBackoff(): void
    {
        $fetcher = $this->fetcher([
            new ConnectException('refused', new Request('GET', 'https://cloud.test/failed')),
            new Response(502),
            $this->page([$this->job('job-1')], next: null),
        ]);

        $jobs = iterator_to_array($fetcher->fetch('https://cloud.test/failed'), false);

        $this->assertSame(['job-1'], array_column($jobs, 'id'));
        $this->assertSame([500, 1000], $this->sleeps);
    }

    public function testItGivesUpAfterTenAttempts(): void
    {
        $fetcher = $this->fetcher(array_fill(0, 10, new Response(500)));

        try {
            iterator_to_array($fetcher->fetch('https://cloud.test/failed'));
            $this->fail('Expected the fetch to give up.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
        }

        $this->assertCount(10, $this->history);
        $this->assertCount(9, $this->sleeps);
    }

    public function testAForbiddenUrlIsNotRetried(): void
    {
        $fetcher = $this->fetcher([new Response(403), $this->page([], next: null)]);

        try {
            iterator_to_array($fetcher->fetch('https://cloud.test/failed'));
            $this->fail('Expected a 403 to fail the fetch.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 403', $e->getMessage());
        }

        $this->assertCount(1, $this->history);
        $this->assertSame([], $this->sleeps);
    }

    public function testItRejectsAnUnsupportedPayloadVersion(): void
    {
        $fetcher = $this->fetcher([
            new Response(200, ['Cloud-Payload-Version' => '2'], self::encryptLikeLaravel('{}', self::SECRET)),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported');

        iterator_to_array($fetcher->fetch('https://cloud.test/failed'));
    }

    /**
     * @param list<Response|\Throwable> $responses
     */
    private function fetcher(array $responses): FailedJobFetcher
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new FailedJobFetcher(
            self::SECRET,
            new Client(['handler' => $stack]),
            fn (int $milliseconds) => $this->sleeps[] = $milliseconds,
        );
    }

    /**
     * @param list<array<string, string>> $jobs
     */
    private function page(array $jobs, ?string $next): Response
    {
        return new Response(200, ['Cloud-Payload-Version' => '1'], self::encryptLikeLaravel(json_encode([
            'data' => $jobs,
            'links' => ['self' => 'https://cloud.test/failed', 'next' => $next],
        ]), self::SECRET));
    }

    /**
     * @return array<string, string>
     */
    private function job(string $id): array
    {
        return ['id' => $id, 'connection' => 'cloud', 'queue' => 'emails', 'payload' => json_encode(['body' => $id])];
    }
}
