<?php

namespace Laravel\Cloud\Symfony\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Laravel\Cloud\Symfony\Agent\AgentClient;
use Laravel\Cloud\Symfony\Agent\AgentUnreachableException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AgentClientTest extends TestCase
{
    /** @var array<int, \Psr\Http\Message\RequestInterface> */
    private array $sent = [];

    public function testNextReturnsTheDecodedPayloadOn200(): void
    {
        $agent = $this->agent([
            new Response(200, [], json_encode([
                'messageId' => 'm-1',
                'receiptHandle' => 'rh-1',
                'body' => 'payload',
                'queueUrl' => 'https://sqs/q',
            ])),
        ]);

        $data = $agent->next();

        $this->assertSame('m-1', $data['messageId']);
        $this->assertSame('rh-1', $data['receiptHandle']);
        $this->assertSame('GET', $this->request(0)->getMethod());
        $this->assertSame('/next', $this->request(0)->getUri()->getPath());
    }

    public function testNextReturnsNullOn204(): void
    {
        $agent = $this->agent([new Response(204)]);

        $this->assertNull($agent->next());
    }

    public function testNextThrowsWhenSocketIsUnreachable(): void
    {
        $agent = $this->agent([
            new ConnectException('Connection refused', new Request('GET', '/next')),
        ]);

        $this->expectException(AgentUnreachableException::class);
        $agent->next();
    }

    public function testNextThrowsOnUnexpectedStatus(): void
    {
        $agent = $this->agent([new Response(500)]);

        $this->expectException(AgentUnreachableException::class);
        $agent->next();
    }

    public function testNextThrowsOnNonArrayBody(): void
    {
        $agent = $this->agent([new Response(200, [], '"just a string"')]);

        $this->expectException(AgentUnreachableException::class);
        $agent->next();
    }

    public function testReportPostsTheOutcome(): void
    {
        $agent = $this->agent([new Response(200)]);

        $agent->report('m-1', 'rh-1', 'processed');

        $this->assertSame('POST', $this->request(0)->getMethod());
        $this->assertSame('/result', $this->request(0)->getUri()->getPath());
        $body = json_decode((string) $this->request(0)->getBody(), true);
        $this->assertSame(['messageId' => 'm-1', 'receiptHandle' => 'rh-1', 'status' => 'processed'], $body);
    }

    public function testReportIncludesDelayForReleasedAndOmitsNulls(): void
    {
        $agent = $this->agent([new Response(200)]);

        $agent->report('m-1', null, 'released', 30);

        $body = json_decode((string) $this->request(0)->getBody(), true);
        $this->assertSame(['messageId' => 'm-1', 'status' => 'released', 'delay' => 30], $body);
        $this->assertArrayNotHasKey('receiptHandle', $body);
    }

    public function testReportRetriesTransientConnectionFailures(): void
    {
        $agent = $this->agent([
            new ConnectException('refused', new Request('POST', '/result')),
            new ConnectException('refused', new Request('POST', '/result')),
            new Response(200),
        ]);

        $agent->report('m-1', 'rh-1', 'processed');

        $this->assertCount(3, $this->sent);
    }

    public function testReportTreatsServerErrorAsAgentUnreachable(): void
    {
        $agent = $this->agent([new Response(503)]);

        $this->expectException(AgentUnreachableException::class);
        $agent->report('m-1', 'rh-1', 'processed');
    }

    public function testReportSurfacesClientErrorAsRuntimeException(): void
    {
        $agent = $this->agent([new Response(409)]);

        $this->expectException(RuntimeException::class);
        $agent->report('m-1', 'rh-1', 'processed');
    }

    private function request(int $index): \Psr\Http\Message\RequestInterface
    {
        return $this->sent[$index]['request'];
    }

    /**
     * @param array<int, Response|\Throwable> $queue
     */
    private function agent(array $queue): AgentClient
    {
        $this->sent = [];
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->sent));

        return new AgentClient('/unused.sock', new Client(['handler' => $stack]));
    }
}
