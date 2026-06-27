<?php

namespace Laravel\Cloud\Symfony\Tests;

use Aws\Sqs\SqsClient;
use Laravel\Cloud\Symfony\Queue\Agent\AgentClient;
use Laravel\Cloud\Symfony\Queue\ManagedQueueConfig;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueReceivedStamp;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueStamp;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueTransport;
use Laravel\Cloud\Symfony\Observability\Events;
use Laravel\Cloud\Symfony\Queue\QueueEventSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Verifies the subscriber emits the same cloud event protocol Laravel does, by
 * capturing the newline-delimited JSON over a real local unix socket.
 */
class QueueEventSubscriberTest extends TestCase
{
    private const QUEUE_URL = 'https://sqs.us-east-2.amazonaws.com/036991040854/default-env-x';

    private string $socketPath = '';

    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        $this->socketPath = sys_get_temp_dir().'/laravel-cloud-events-'.bin2hex(random_bytes(4)).'.sock';

        $server = @stream_socket_server('unix://'.$this->socketPath, $errno, $errstr);

        if ($server === false) {
            $this->markTestSkipped("Unable to bind a unix socket: {$errstr} ({$errno})");
        }

        $this->server = $server;
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }

        @unlink($this->socketPath);
    }

    public function testItEmitsAQueuedEventWhenSendingToTheManagedTransport(): void
    {
        $subscriber = $this->subscriber();

        $event = new SendMessageToTransportsEvent(
            new Envelope(new DummyMessage('hi')),
            ['cloud' => $this->cloudTransport()],
        );

        $lines = $this->capture(fn () => $subscriber->onSend($event));

        $this->assertCount(1, $lines);
        $this->assertSame('queue', $lines[0]['_cloud_event']);
        $this->assertSame('queued', $lines[0]['type']);
        $this->assertSame('default', $lines[0]['queue']);
        $this->assertNotEmpty($lines[0]['timestamp']);
    }

    public function testTheQueuedEventReportsTheStampedQueue(): void
    {
        $subscriber = $this->subscriber();

        $event = new SendMessageToTransportsEvent(
            new Envelope(new DummyMessage('hi'), [new CloudQueueStamp('critical')]),
            ['cloud' => $this->cloudTransport()],
        );

        $lines = $this->capture(fn () => $subscriber->onSend($event));

        $this->assertCount(1, $lines);
        $this->assertSame('queued', $lines[0]['type']);
        // The CloudQueueStamp wins over the container's default queue, so the
        // enqueue metric lands on the same queue send() pushes to.
        $this->assertSame('critical', $lines[0]['queue']);
    }

    public function testTheQueuedEventReportsAFifoQueueByItsLogicalName(): void
    {
        $subscriber = $this->subscriber();

        $event = new SendMessageToTransportsEvent(
            new Envelope(new DummyMessage('hi'), [new CloudQueueStamp('orders.fifo')]),
            ['cloud' => $this->cloudTransport()],
        );

        $lines = $this->capture(fn () => $subscriber->onSend($event));

        $this->assertCount(1, $lines);
        $this->assertSame('queued', $lines[0]['type']);
        // The dashboard groups under the logical ".fifo" name, not a full URL.
        $this->assertSame('orders.fifo', $lines[0]['queue']);
    }

    public function testWorkerEventsReportAFifoQueueByItsLogicalName(): void
    {
        $subscriber = $this->subscriber();

        // The received stamp carries the real, fully suffixed FIFO queue URL the
        // message came from; it must normalize back to "orders.fifo".
        $fifoUrl = 'https://sqs.us-east-2.amazonaws.com/036991040854/orders-env-x.fifo';
        $envelope = (new Envelope(new DummyMessage('work')))
            ->with(new CloudQueueReceivedStamp('m-1', 'rh-1', $fifoUrl, fromAgent: true, body: 'raw-body'));
        $received = new WorkerMessageReceivedEvent($envelope, 'cloud');

        $lines = $this->capture(function () use ($subscriber, $received) {
            $subscriber->onReceived($received);
            $subscriber->onHandled(new WorkerMessageHandledEvent($received->getEnvelope(), 'cloud'));
        });

        $this->assertCount(2, $lines);
        $this->assertSame('orders.fifo', $lines[0]['queue']);
        $this->assertSame('orders.fifo', $lines[1]['queue']);
    }

    public function testItIgnoresSendsToOtherTransports(): void
    {
        $subscriber = $this->subscriber();

        $other = new class {};
        $event = new SendMessageToTransportsEvent(new Envelope(new DummyMessage('hi')), ['other' => $other]);

        $this->assertSame([], $this->capture(fn () => $subscriber->onSend($event)));
    }

    public function testItEmitsStartedThenProcessedWithADuration(): void
    {
        $subscriber = $this->subscriber();

        $received = new WorkerMessageReceivedEvent($this->managedEnvelope(), 'cloud');

        $lines = $this->capture(function () use ($subscriber, $received) {
            $subscriber->onReceived($received);

            // The worker carries the envelope (now stamped with the start time)
            // forward to the handled event.
            usleep(2000);
            $subscriber->onHandled(new WorkerMessageHandledEvent($received->getEnvelope(), 'cloud'));
        });

        $this->assertCount(2, $lines);

        $this->assertSame('started', $lines[0]['type']);
        $this->assertSame('default', $lines[0]['queue']);
        $this->assertArrayNotHasKey('duration_ms', $lines[0]);

        $this->assertSame('processed', $lines[1]['type']);
        $this->assertSame('default', $lines[1]['queue']);
        $this->assertGreaterThanOrEqual(0, $lines[1]['duration_ms']);
    }

    public function testATerminalFailureEmitsFailedAndAFailedJob(): void
    {
        $subscriber = $this->subscriber();

        // Failed on its 3rd SQS delivery: the failed_job attempt count comes from
        // ApproximateReceiveCount (no RedeliveryStamp exists under SQS-native retry).
        $received = new WorkerMessageReceivedEvent($this->managedEnvelope(receiveCount: 3), 'cloud');

        $lines = $this->capture(function () use ($subscriber, $received) {
            $subscriber->onReceived($received);

            $failed = new WorkerMessageFailedEvent(
                $received->getEnvelope(),
                'cloud',
                new \RuntimeException('boom'),
            );
            // willRetry() defaults to false: a terminal failure.
            $subscriber->onFailed($failed);
        });

        $this->assertCount(3, $lines);
        $this->assertSame('started', $lines[0]['type']);

        $this->assertSame('queue', $lines[1]['_cloud_event']);
        $this->assertSame('failed', $lines[1]['type']);

        $this->assertSame('failed_job', $lines[2]['_cloud_event']);
        $this->assertSame('default', $lines[2]['queue']);
        $this->assertSame(3, $lines[2]['attempts']);
        $this->assertSame('raw-body', $lines[2]['payload']);
        $this->assertStringContainsString('boom', $lines[2]['exception']);
        $this->assertNotEmpty($lines[2]['id']);
        $this->assertNotEmpty($lines[2]['started_at']);
    }

    public function testARetryableFailureEmitsReleasedAndNoFailedJob(): void
    {
        $subscriber = $this->subscriber();

        $received = new WorkerMessageReceivedEvent($this->managedEnvelope(), 'cloud');

        $lines = $this->capture(function () use ($subscriber, $received) {
            $subscriber->onReceived($received);

            $failed = new WorkerMessageFailedEvent($received->getEnvelope(), 'cloud', new \RuntimeException('again'));
            // CloudRetrySubscriber would set this; here we drive it directly.
            $failed->setForRetry();
            $subscriber->onFailed($failed);
        });

        $this->assertCount(2, $lines);
        $this->assertSame('started', $lines[0]['type']);
        $this->assertSame('released', $lines[1]['type']);
    }

    public function testItIgnoresWorkerEventsForMessagesFromOtherTransports(): void
    {
        $subscriber = $this->subscriber();

        $event = new WorkerMessageReceivedEvent(new Envelope(new DummyMessage('x')), 'other');

        $this->assertSame([], $this->capture(fn () => $subscriber->onReceived($event)));
    }

    private function subscriber(): QueueEventSubscriber
    {
        return new QueueEventSubscriber(new Events('unix://'.$this->socketPath), $this->config());
    }

    private function managedEnvelope(int $receiveCount = 1): Envelope
    {
        return (new Envelope(new DummyMessage('work')))
            ->with(new CloudQueueReceivedStamp('m-1', 'rh-1', self::QUEUE_URL, fromAgent: true, body: 'raw-body', approximateReceiveCount: $receiveCount));
    }

    private function config(): ManagedQueueConfig
    {
        return ManagedQueueConfig::fromEnvironment(
            json_encode(['connection' => [
                'prefix' => 'https://sqs.us-east-2.amazonaws.com/036991040854',
                'suffix' => '-env-x',
                'queue' => 'default',
                'region' => 'us-east-2',
            ]]),
            '/unused.sock',
        );
    }

    private function cloudTransport(): CloudQueueTransport
    {
        $sqs = new SqsClient([
            'region' => 'us-east-2',
            'version' => 'latest',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        return new CloudQueueTransport($sqs, new AgentClient('/unused.sock'), $this->config(), new PhpSerializer(), false);
    }

    /**
     * Run the emitting closure and return the decoded JSON lines the socket
     * server received.
     *
     * @return list<array<string, mixed>>
     */
    private function capture(callable $emit): array
    {
        $emit();

        $client = @stream_socket_accept($this->server, 2);

        if ($client === false) {
            return [];
        }

        stream_set_blocking($client, false);

        $buffer = '';
        $deadline = microtime(true) + 1;

        while (microtime(true) < $deadline) {
            $chunk = fread($client, 8192);

            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
                // Keep reading briefly in case more lines are still in flight.
                $deadline = microtime(true) + 0.1;
            } else {
                usleep(5000);
            }
        }

        fclose($client);

        $lines = [];

        foreach (explode("\n", trim($buffer)) as $line) {
            if ($line !== '') {
                $lines[] = json_decode($line, true);
            }
        }

        return $lines;
    }
}
