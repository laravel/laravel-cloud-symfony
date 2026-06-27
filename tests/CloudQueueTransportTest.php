<?php

namespace Laravel\Cloud\Symfony\Tests;

use Aws\CommandInterface;
use Aws\MockHandler as AwsMockHandler;
use Aws\Result;
use Aws\Sqs\SqsClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler as GuzzleMockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Laravel\Cloud\Symfony\Queue\Agent\AgentClient;
use Laravel\Cloud\Symfony\Queue\ManagedQueueConfig;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudFifoStamp;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudMessageGroupStamp;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueReceivedStamp;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueStamp;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class CloudQueueTransportTest extends TestCase
{
    private const QUEUE_URL = 'https://sqs.us-east-2.amazonaws.com/036991040854/default-env-x';

    /** @var array<int, array<string, mixed>> */
    private array $sqsCommands = [];
    /** @var array<int, \Psr\Http\Message\RequestInterface> */
    private array $agentRequests = [];
    private AwsMockHandler $sqsMock;
    private GuzzleMockHandler $agentMock;
    private SerializerInterface $serializer;

    protected function setUp(): void
    {
        $this->sqsCommands = [];
        $this->agentRequests = [];
        $this->sqsMock = new AwsMockHandler();
        $this->agentMock = new GuzzleMockHandler();
        $this->serializer = new PhpSerializer();
    }

    public function testSendPushesToSqsAndStampsTheMessageId(): void
    {
        $this->sqsMock->append(function (CommandInterface $cmd) {
            $this->sqsCommands[] = $cmd->toArray();

            return new Result(['MessageId' => 'sqs-message-id']);
        });

        $transport = $this->transport(useAgent: false);
        $envelope = $transport->send(new Envelope(new DummyMessage('hello')));

        $this->assertSame(self::QUEUE_URL, $this->sqsCommands[0]['QueueUrl']);
        $this->assertArrayNotHasKey('DelaySeconds', $this->sqsCommands[0]);
        $this->assertSame('sqs-message-id', $envelope->last(TransportMessageIdStamp::class)?->getId());

        // The body is a self-contained JSON envelope wrapper.
        $wrapper = json_decode($this->sqsCommands[0]['MessageBody'], true);
        $this->assertArrayHasKey('body', $wrapper);
        $this->assertArrayHasKey('headers', $wrapper);

        // Laravel-shaped metadata so the Cloud failed-job dashboard can name the
        // job (its display name is "typically its class").
        $this->assertSame(DummyMessage::class, $wrapper['displayName']);
        $this->assertNotEmpty($wrapper['uuid']);
    }

    public function testSendRoutesToANamedQueueWhenStamped(): void
    {
        $this->sqsMock->append(function (CommandInterface $cmd) {
            $this->sqsCommands[] = $cmd->toArray();

            return new Result(['MessageId' => 'id']);
        });

        $transport = $this->transport(useAgent: false);
        $transport->send((new Envelope(new DummyMessage('urgent')))->with(new CloudQueueStamp('critical')));

        $this->assertSame(
            'https://sqs.us-east-2.amazonaws.com/036991040854/critical-env-x',
            $this->sqsCommands[0]['QueueUrl'],
        );
    }

    public function testSendToAFifoQueueSetsTheGroupAndDeduplicationIds(): void
    {
        $this->sqsMock->append(function (CommandInterface $cmd) {
            $this->sqsCommands[] = $cmd->toArray();

            return new Result(['MessageId' => 'id']);
        });

        $transport = $this->transport(useAgent: false);
        $transport->send((new Envelope(new DummyMessage('ordered')))->with(new CloudQueueStamp('orders.fifo')));

        $cmd = $this->sqsCommands[0];

        // The ".fifo" marker survives suffixing at the very end of the URL.
        $this->assertSame(
            'https://sqs.us-east-2.amazonaws.com/036991040854/orders-env-x.fifo',
            $cmd['QueueUrl'],
        );
        // SQS requires a group ID on FIFO queues; it defaults to the queue name.
        $this->assertSame('orders.fifo', $cmd['MessageGroupId']);
        // And a deduplication ID, which defaults to a unique (non-empty) value.
        $this->assertNotEmpty($cmd['MessageDeduplicationId']);
    }

    public function testSendToAFifoQueueHonoursAnExplicitCloudFifoStamp(): void
    {
        $this->sqsMock->append(function (CommandInterface $cmd) {
            $this->sqsCommands[] = $cmd->toArray();

            return new Result(['MessageId' => 'id']);
        });

        $transport = $this->transport(useAgent: false);
        $transport->send((new Envelope(new DummyMessage('ordered')))
            ->with(new CloudQueueStamp('orders.fifo'))
            ->with(new CloudFifoStamp(messageGroupId: 'customer-7', messageDeduplicationId: 'dedup-key')));

        $this->assertSame('customer-7', $this->sqsCommands[0]['MessageGroupId']);
        $this->assertSame('dedup-key', $this->sqsCommands[0]['MessageDeduplicationId']);
    }

    public function testACloudFifoStampOnAStandardQueueIsRejected(): void
    {
        $transport = $this->transport(useAgent: false);

        $this->expectException(TransportException::class);

        $transport->send((new Envelope(new DummyMessage('oops')))
            ->with(new CloudFifoStamp(messageDeduplicationId: 'd')));
    }

    public function testSendToAStandardQueueSetsTheFairQueueMessageGroup(): void
    {
        $this->sqsMock->append(function (CommandInterface $cmd) {
            $this->sqsCommands[] = $cmd->toArray();

            return new Result(['MessageId' => 'id']);
        });

        $transport = $this->transport(useAgent: false);
        $transport->send((new Envelope(new DummyMessage('tenant work')))
            ->with(new CloudMessageGroupStamp('customer-7')));

        $cmd = $this->sqsCommands[0];

        // The group ID enables SQS fair queues as a pure tenant key...
        $this->assertSame('customer-7', $cmd['MessageGroupId']);
        // ...with no deduplication ID, which standard queues reject.
        $this->assertArrayNotHasKey('MessageDeduplicationId', $cmd);
    }

    public function testACloudMessageGroupStampOnAFifoQueueIsRejected(): void
    {
        $transport = $this->transport(useAgent: false);

        $this->expectException(TransportException::class);

        $transport->send((new Envelope(new DummyMessage('oops')))
            ->with(new CloudQueueStamp('orders.fifo'))
            ->with(new CloudMessageGroupStamp('customer-7')));
    }

    public function testSendToAFifoQueueIgnoresTheDelayStamp(): void
    {
        $this->sqsMock->append(function (CommandInterface $cmd) {
            $this->sqsCommands[] = $cmd->toArray();

            return new Result(['MessageId' => 'id']);
        });

        $transport = $this->transport(useAgent: false);
        // FIFO queues reject per-message delivery delay, so it must not be sent.
        $transport->send((new Envelope(new DummyMessage('ordered')))
            ->with(new CloudQueueStamp('orders.fifo'))
            ->with(new DelayStamp(5000)));

        $this->assertArrayNotHasKey('DelaySeconds', $this->sqsCommands[0]);
    }

    public function testACallerSetDelayBeyondFifteenMinutesIsRejected(): void
    {
        $transport = $this->transport(useAgent: false);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('exceeds the SQS maximum');

        // The caller asked for a one-hour delay; SQS can't honour it, so we must
        // say so rather than silently deliver after 15 minutes.
        $transport->send((new Envelope(new DummyMessage('in an hour')))
            ->with(new DelayStamp(3_600_000)));
    }

    public function testARetryBackoffBeyondFifteenMinutesIsClampedNotRejected(): void
    {
        $this->sqsMock->append(function (CommandInterface $cmd) {
            $this->sqsCommands[] = $cmd->toArray();

            return new Result(['MessageId' => 'id']);
        });

        $transport = $this->transport(useAgent: false);

        // A retry re-sends with a RedeliveryStamp; its backoff is best-effort, so
        // a value past the cap is clamped to 15 minutes rather than breaking the
        // retry.
        $transport->send((new Envelope(new DummyMessage('retry')))
            ->with(new DelayStamp(3_600_000))
            ->with(new RedeliveryStamp(2)));

        $this->assertSame(900, $this->sqsCommands[0]['DelaySeconds']);
    }

    public function testGetFromQueuesReceivesFromTheNamedQueue(): void
    {
        $this->sqsMock->append(function (CommandInterface $cmd) {
            $this->sqsCommands[] = $cmd->toArray();

            return new Result(['MessageId' => 'id']);
        });
        $transport = $this->transport(useAgent: false);
        $transport->send((new Envelope(new DummyMessage('crit')))->with(new CloudQueueStamp('critical')));
        $body = $this->sqsCommands[0]['MessageBody'];

        $this->sqsMock->append(function (CommandInterface $cmd) use ($body) {
            $this->sqsCommands[] = $cmd->toArray();

            return new Result(['Messages' => [[
                'MessageId' => 'sqs-1',
                'ReceiptHandle' => 'sqs-rh-1',
                'Body' => $body,
            ]]]);
        });

        $envelopes = [...$transport->getFromQueues(['critical'])];

        $this->assertCount(1, $envelopes);
        $this->assertEquals(new DummyMessage('crit'), $envelopes[0]->getMessage());
        // It polled the critical queue, and the received stamp records it so the
        // outcome is reported back to the same queue.
        $this->assertSame(
            'https://sqs.us-east-2.amazonaws.com/036991040854/critical-env-x',
            end($this->sqsCommands)['QueueUrl'],
        );
        $this->assertSame(
            'https://sqs.us-east-2.amazonaws.com/036991040854/critical-env-x',
            $envelopes[0]->last(CloudQueueReceivedStamp::class)->queueUrl,
        );
    }

    public function testGetFromQueuesUsesTheAgentRegardlessOfName(): void
    {
        // The in-container agent is pinned to one queue; --queues can't repoint it.
        $this->agentMock->append(new Response(204));

        $transport = $this->transport(useAgent: true);

        $this->assertSame([], [...$transport->getFromQueues(['critical'])]);
        $this->assertSame('/next', $this->agentRequests[0]['request']->getUri()->getPath());
    }

    public function testSendConvertsTheDelayStampToWholeSeconds(): void
    {
        $this->sqsMock->append(function (CommandInterface $cmd) {
            $this->sqsCommands[] = $cmd->toArray();

            return new Result(['MessageId' => 'id']);
        });

        $transport = $this->transport(useAgent: false);
        $transport->send((new Envelope(new DummyMessage('later')))->with(new DelayStamp(5000)));

        $this->assertSame(5, $this->sqsCommands[0]['DelaySeconds']);
    }

    public function testItReceivesFromTheAgentAcknowledgesAndRoundTripsTheMessage(): void
    {
        // Push a job to capture the exact body the agent would hand back.
        $this->sqsMock->append(function (CommandInterface $cmd) {
            $this->sqsCommands[] = $cmd->toArray();

            return new Result(['MessageId' => 'id']);
        });

        $transport = $this->transport(useAgent: true);
        $transport->send(new Envelope(new DummyMessage('round-trip')));
        $body = $this->sqsCommands[0]['MessageBody'];

        // The agent serves that body from a (possibly different) queue URL.
        $this->agentMock->append(new Response(200, [], json_encode([
            'messageId' => 'm-1',
            'receiptHandle' => 'rh-1',
            'body' => $body,
            'queueUrl' => 'https://sqs/agent-queue',
        ])));

        $envelopes = [...$transport->get()];
        $this->assertCount(1, $envelopes);

        $received = $envelopes[0];
        $this->assertEquals(new DummyMessage('round-trip'), $received->getMessage());

        $stamp = $received->last(CloudQueueReceivedStamp::class);
        $this->assertInstanceOf(CloudQueueReceivedStamp::class, $stamp);
        $this->assertTrue($stamp->fromAgent);
        $this->assertSame('m-1', $stamp->messageId);
        $this->assertSame('https://sqs/agent-queue', $stamp->queueUrl);
        $this->assertSame('m-1', $received->last(TransportMessageIdStamp::class)?->getId());

        // Acknowledging reports "processed" to the agent — never touches SQS.
        $this->agentMock->append(new Response(200));
        $transport->ack($received);

        $result = $this->agentRequests[1]['request'];
        $this->assertSame('POST', $result->getMethod());
        $this->assertSame('/result', $result->getUri()->getPath());
        $this->assertSame('processed', json_decode((string) $result->getBody(), true)['status']);
    }

    public function testGetReturnsNothingWhenTheAgentIsEmpty(): void
    {
        $this->agentMock->append(new Response(204));

        $transport = $this->transport(useAgent: true);

        $this->assertSame([], [...$transport->get()]);
    }

    public function testRejectAlsoReportsProcessed(): void
    {
        $received = (new Envelope(new DummyMessage('x')))
            ->with(new CloudQueueReceivedStamp('m-9', 'rh-9', self::QUEUE_URL, fromAgent: true));

        $this->agentMock->append(new Response(200));
        $this->transport(useAgent: true)->reject($received);

        $this->assertSame('processed', json_decode((string) $this->agentRequests[0]['request']->getBody(), true)['status']);
    }

    public function testItReceivesDirectlyFromSqsWhenTheAgentIsDisabled(): void
    {
        $this->sqsMock->append(function (CommandInterface $cmd) {
            $this->sqsCommands[] = $cmd->toArray();

            return new Result(['MessageId' => 'id']);
        });
        $transport = $this->transport(useAgent: false);
        $transport->send(new Envelope(new DummyMessage('from-sqs')));
        $body = $this->sqsCommands[0]['MessageBody'];

        // Now SQS returns that message on receive, and ack deletes it.
        $this->sqsMock->append(new Result(['Messages' => [[
            'MessageId' => 'sqs-1',
            'ReceiptHandle' => 'sqs-rh-1',
            'Body' => $body,
        ]]]));
        $this->sqsMock->append(function (CommandInterface $cmd) {
            $this->sqsCommands[] = $cmd->toArray();

            return new Result([]);
        });

        $envelopes = [...$transport->get()];
        $this->assertEquals(new DummyMessage('from-sqs'), $envelopes[0]->getMessage());
        $this->assertFalse($envelopes[0]->last(CloudQueueReceivedStamp::class)->fromAgent);

        $transport->ack($envelopes[0]);
        $delete = end($this->sqsCommands);
        $this->assertSame('sqs-rh-1', $delete['ReceiptHandle']);
        $this->assertSame(self::QUEUE_URL, $delete['QueueUrl']);
    }

    private function transport(bool $useAgent): CloudQueueTransport
    {
        $config = ManagedQueueConfig::fromEnvironment(
            json_encode(['connection' => [
                'prefix' => 'https://sqs.us-east-2.amazonaws.com/036991040854',
                'suffix' => '-env-x',
                'queue' => 'default',
                'region' => 'us-east-2',
            ]]),
            '/unused.sock',
        );

        $sqs = new SqsClient([
            'region' => 'us-east-2',
            'version' => 'latest',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $this->sqsMock,
        ]);

        $stack = HandlerStack::create($this->agentMock);
        $stack->push(Middleware::history($this->agentRequests));
        $agent = new AgentClient('/unused.sock', new Client(['handler' => $stack]));

        return new CloudQueueTransport($sqs, $agent, $config, $this->serializer, $useAgent);
    }
}
