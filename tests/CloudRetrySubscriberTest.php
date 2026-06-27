<?php

namespace Laravel\Cloud\Symfony\Tests;

use Laravel\Cloud\Symfony\Queue\CloudRetrySubscriber;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueReceivedStamp;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudReleaseStamp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

class CloudRetrySubscriberTest extends TestCase
{
    public function testItReleasesARetryableFailureWithABackoffDelay(): void
    {
        $event = $this->event($this->received(receiveCount: 1));

        (new CloudRetrySubscriber())->onFailed($event);

        // Marked for retry, and stamped so the transport releases (not deletes).
        $this->assertTrue($event->willRetry());
        $release = $event->getEnvelope()->last(CloudReleaseStamp::class);
        $this->assertInstanceOf(CloudReleaseStamp::class, $release);
        $this->assertSame(1, $release->delaySeconds); // base 1000ms on attempt 1
    }

    public function testBackoffGrowsWithTheAttemptNumber(): void
    {
        $event = $this->event($this->received(receiveCount: 2));

        (new CloudRetrySubscriber())->onFailed($event);

        // 1000ms * 2^(2-1) = 2000ms = 2s.
        $this->assertSame(2, $event->getEnvelope()->last(CloudReleaseStamp::class)?->delaySeconds);
    }

    public function testItDoesNotRetryOnceAttemptsAreExhausted(): void
    {
        $event = $this->event($this->received(receiveCount: 3));

        (new CloudRetrySubscriber(maxAttempts: 3))->onFailed($event);

        // Terminal: no retry, no release stamp — reject() will delete it.
        $this->assertFalse($event->willRetry());
        $this->assertNull($event->getEnvelope()->last(CloudReleaseStamp::class));
    }

    public function testItDoesNotRetryAnUnrecoverableException(): void
    {
        $event = $this->event(
            $this->received(receiveCount: 1),
            new UnrecoverableMessageHandlingException('do not retry'),
        );

        (new CloudRetrySubscriber())->onFailed($event);

        $this->assertFalse($event->willRetry());
        $this->assertNull($event->getEnvelope()->last(CloudReleaseStamp::class));
    }

    public function testItIgnoresMessagesFromOtherTransports(): void
    {
        // No CloudQueueReceivedStamp — not a managed-queue message.
        $event = $this->event(new Envelope(new DummyMessage('x')));

        (new CloudRetrySubscriber())->onFailed($event);

        $this->assertFalse($event->willRetry());
        $this->assertNull($event->getEnvelope()->last(CloudReleaseStamp::class));
    }

    private function received(int $receiveCount): Envelope
    {
        return (new Envelope(new DummyMessage('x')))
            ->with(new CloudQueueReceivedStamp('m-1', 'rh-1', 'https://sqs/q', fromAgent: true, approximateReceiveCount: $receiveCount));
    }

    private function event(Envelope $envelope, ?\Throwable $error = null): WorkerMessageFailedEvent
    {
        return new WorkerMessageFailedEvent($envelope, 'cloud', $error ?? new \RuntimeException('boom'));
    }
}
