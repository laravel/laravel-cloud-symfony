<?php

namespace Laravel\Cloud\Symfony\Queue;

use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueReceivedStamp;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudReleaseStamp;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * Retries failed managed-queue messages the way Laravel does: by releasing the
 * message back to SQS for native redelivery (a visibility-timeout reset) rather
 * than Symfony Messenger's default of re-sending a fresh copy.
 *
 * Messenger's re-send retry is disabled for the cloud transport (its
 * retry_strategy has max_retries: 0, see QueueFeature), so this subscriber owns
 * the retry decision. On a retryable failure it marks the event for retry and
 * stamps a CloudReleaseStamp carrying the backoff delay; the transport's
 * reject() then performs the SQS release. A terminal failure is left untouched —
 * willRetry() stays false, so the message is deleted and recorded as a failed
 * job by QueueEventSubscriber.
 *
 * The attempt count comes from SQS's ApproximateReceiveCount (each redelivery
 * increments it), mirroring Laravel's SqsJob::attempts(); there is no
 * RedeliveryStamp to count because nothing is re-sent.
 *
 * Runs at priority 0 — after Messenger's retry listener (100, a no-op here) and
 * before the failure-transport listener and QueueEventSubscriber (both -100), so
 * willRetry() is set before they read it.
 */
final class CloudRetrySubscriber implements EventSubscriberInterface
{
    /**
     * @param int   $maxAttempts total delivery attempts before a job is failed
     * @param int   $baseDelay   first retry backoff, in milliseconds
     * @param float $multiplier  backoff multiplier applied per attempt
     * @param int   $maxDelay    backoff ceiling in milliseconds; 0 means no cap
     */
    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelay = 1000,
        private readonly float $multiplier = 2.0,
        private readonly int $maxDelay = 0,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => ['onFailed', 0],
        ];
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $received = $event->getEnvelope()->last(CloudQueueReceivedStamp::class);

        if (! $received instanceof CloudQueueReceivedStamp) {
            return; // not one of ours — leave other transports' messages alone
        }

        if ($event->willRetry()) {
            return; // already marked for retry elsewhere; don't double up
        }

        if (! $this->shouldRetry($event->getThrowable(), $received->approximateReceiveCount)) {
            return; // terminal: reject() deletes and the failed job is recorded
        }

        // Release the message back to SQS for redelivery after the backoff. The
        // stamp travels on the envelope the worker hands to reject().
        $event->setForRetry();
        $event->addStamps(new CloudReleaseStamp($this->backoffSeconds($received->approximateReceiveCount)));
    }

    private function shouldRetry(\Throwable $throwable, int $attempts): bool
    {
        if ($this->isUnrecoverable($throwable)) {
            return false;
        }

        return $attempts < $this->maxAttempts;
    }

    /**
     * An UnrecoverableExceptionInterface — directly, or wrapped by a handler
     * failure — opts the message out of retries, matching Messenger's own rule.
     */
    private function isUnrecoverable(\Throwable $throwable): bool
    {
        if ($throwable instanceof UnrecoverableExceptionInterface) {
            return true;
        }

        if ($throwable instanceof HandlerFailedException) {
            foreach ($throwable->getWrappedExceptions() as $nested) {
                if ($nested instanceof UnrecoverableExceptionInterface) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Backoff for the current attempt, in whole seconds, clamped to the SQS
     * visibility-timeout ceiling of 12 hours.
     */
    private function backoffSeconds(int $attempts): int
    {
        $delayMs = (int) round($this->baseDelay * $this->multiplier ** ($attempts - 1));

        if ($this->maxDelay > 0) {
            $delayMs = min($delayMs, $this->maxDelay);
        }

        return max(0, min(43200, intdiv($delayMs, 1000)));
    }
}
