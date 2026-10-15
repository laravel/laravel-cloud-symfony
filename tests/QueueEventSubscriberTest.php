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
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Verifies the subscriber emits the same cloud event protocol Laravel does, by
 * capturing the newline-delimited JSON over a real local unix socket.
 */
class QueueEventSubscriberTest extends TestCase
{
    private const QUEUE_URL = 'https://sqs.us-east-2.amazonaws.com/036991040854/default-env-x';

    use CapturesCloudEvents;

    protected function setUp(): void
    {
        $this->openEventSocket();
    }

    protected function tearDown(): void
    {
        $this->closeEventSocket();
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

    public function testATerminalFailureEmitsAFailedJobThenFailed(): void
    {
        $subscriber = $this->subscriber();

        // Failed on its 3rd SQS delivery: the failed_job attempt count comes from
        // ApproximateReceiveCount (the true delivery count, which the transport
        // also bridges into a RedeliveryStamp for the retry strategy).
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

        $this->assertSame('failed_job', $lines[1]['_cloud_event']);
        $this->assertSame('default', $lines[1]['queue']);
        $this->assertSame(3, $lines[1]['attempts']);
        $this->assertStringContainsString('boom', $lines[1]['exception']);
        $this->assertNotEmpty($lines[1]['id']);
        $this->assertNotEmpty($lines[1]['started_at']);

        $this->assertSame('queue', $lines[2]['_cloud_event']);
        $this->assertSame('failed', $lines[2]['type']);
    }

    public function testTheFailedJobCarriesTheWholePayloadAndLaravelsSummaryFields(): void
    {
        $subscriber = $this->subscriber();

        // Larger than the old trim limit; must arrive byte-for-byte.
        $payload = json_encode([
            'uuid' => 'a-uuid',
            'displayName' => DummyMessage::class,
            'body' => base64_encode(str_repeat('x', 40_000)),
            'headers' => ['type' => DummyMessage::class],
        ]);

        $envelope = (new Envelope(new DummyMessage('work')))
            ->with(new CloudQueueReceivedStamp('m-1', 'rh-1', self::QUEUE_URL, fromAgent: true, body: $payload));

        $cause = new \LogicException('the real cause');
        $error = new HandlerFailedException($envelope, ['App\\Handler::__invoke' => $cause]);

        $lines = $this->capture(fn () => $subscriber->onFailed(new WorkerMessageFailedEvent($envelope, 'cloud', $error)));

        $this->assertSame('failed_job', $lines[0]['_cloud_event']);
        $this->assertSame($payload, $lines[0]['payload']);
        $this->assertSame(DummyMessage::class, $lines[0]['job_name']);
        $this->assertSame(
            'LogicException: the real cause in '.$cause->getFile().':'.$cause->getLine(),
            $lines[0]['exception_preview'],
        );
        $this->assertStringContainsString(HandlerFailedException::class, $lines[0]['exception']);
    }

    public function testARetryableFailureEmitsReleasedAndNoFailedJob(): void
    {
        $subscriber = $this->subscriber();

        $received = new WorkerMessageReceivedEvent($this->managedEnvelope(), 'cloud');

        $lines = $this->capture(function () use ($subscriber, $received) {
            $subscriber->onReceived($received);

            $failed = new WorkerMessageFailedEvent($received->getEnvelope(), 'cloud', new \RuntimeException('again'));
            // Messenger's retry listener would set this; here we drive it directly.
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

    public function testEndToEndTheRealRetryListenerDrivesTheFailedJobDecision(): void
    {
        // Wire the *real* Symfony retry listener (priority 100) and our subscriber
        // (priority -100) onto one dispatcher, the way the framework does. This
        // proves willRetry() flows from the stock strategy to our observability
        // exactly as before, now that CloudRetrySubscriber is gone.
        $strategy = new MultiplierRetryStrategy(maxRetries: 3, delayMilliseconds: 1000, multiplier: 2.0, maxDelayMilliseconds: 0, jitter: 0.0);

        // A retry attempt re-sends; the listener only needs *a* sender, and we
        // don't want the transport's release side effect in an observability test,
        // so a no-op sender stands in.
        $sender = new class implements SenderInterface {
            public function send(Envelope $envelope): Envelope
            {
                return $envelope;
            }
        };

        $retryListener = new SendFailedMessageForRetryListener(
            $this->locator(['cloud' => $sender]),
            $this->locator(['cloud' => $strategy]),
        );

        $dispatch = function (int $receiveCount) use ($retryListener): array {
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(WorkerMessageFailedEvent::class, $retryListener->onMessageFailed(...), 100);
            $dispatcher->addListener(WorkerMessageFailedEvent::class, $this->subscriber()->onFailed(...), -100);

            // Mirror decode(): the received stamp + the RedeliveryStamp bridged
            // from ApproximateReceiveCount, which is what lets the stock strategy
            // reach its verdict.
            $envelope = $this->managedEnvelope($receiveCount)
                ->with(new RedeliveryStamp(max(0, $receiveCount - 1)));

            return $this->capture(fn () => $dispatcher->dispatch(
                new WorkerMessageFailedEvent($envelope, 'cloud', new \RuntimeException('boom')),
            ));
        };

        // 2nd delivery (max_retries 3) → still retryable → "released", no failed_job.
        $retry = $dispatch(2);
        $this->assertSame(['released'], array_column($retry, 'type'));
        $this->assertNotContains('failed_job', array_column($retry, '_cloud_event'));

        // 4th delivery → strategy is exhausted → "failed" + a recorded failed_job.
        $terminal = $dispatch(4);
        $this->assertSame('failed_job', $terminal[0]['_cloud_event']);
        $this->assertSame(4, $terminal[0]['attempts']);
        $this->assertStringContainsString('boom', $terminal[0]['exception']);
        $this->assertSame('failed', $terminal[1]['type']);
    }

    /**
     * @param array<string, object> $services
     */
    private function locator(array $services): \Psr\Container\ContainerInterface
    {
        return new \Symfony\Component\DependencyInjection\ServiceLocator(array_map(
            static fn (object $service): \Closure => static fn (): object => $service,
            $services,
        ));
    }

    private function subscriber(): QueueEventSubscriber
    {
        return new QueueEventSubscriber(new Events($this->eventSocketAddress()), $this->config());
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

        return new CloudQueueTransport($sqs, new AgentClient($this->config()), $this->config(), new PhpSerializer(), false);
    }
}
