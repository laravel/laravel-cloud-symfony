<?php

namespace Laravel\Cloud\Symfony\Queue;

use Laravel\Cloud\Symfony\Observability\Events;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudProcessingStartedStamp;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueReceivedStamp;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueStamp;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueTransport;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Uid\Uuid;

/**
 * Emits Laravel Cloud queue observability events for messages flowing through
 * the managed-queue transport, so the Cloud dashboard shows the same throughput,
 * latency and failure metrics for a Symfony app as it does for a Laravel one.
 *
 * The wire protocol matches Laravel\Foundation\Cloud\Queue + FailedJobProvider:
 *
 *   - "queued"    when a message is dispatched onto the managed queue
 *   - "started"   when a worker receives a managed-queue message
 *   - "processed" when handling succeeds
 *   - "released"  when handling fails but the message will be retried
 *   - "failed"    when handling fails terminally
 *   - "failed_job" the full payload + exception for a terminal failure
 *
 * Worker events fire for every transport, so each handler first checks for the
 * CloudQueueReceivedStamp our transport adds — that, not the (user-chosen)
 * transport name, is how we recognise our own messages.
 */
final class QueueEventSubscriber implements EventSubscriberInterface
{
    private const MAX_EXCEPTION_PREVIEW_CHARS = 1001;

    private readonly \DateTimeZone $utc;

    public function __construct(
        private readonly Events $events,
        private readonly ManagedQueueConfig $config,
    ) {
        $this->utc = new \DateTimeZone('UTC');
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SendMessageToTransportsEvent::class => 'onSend',
            WorkerMessageReceivedEvent::class => 'onReceived',
            WorkerMessageHandledEvent::class => 'onHandled',
            // Run after Messenger's retry listener (priority 100) so willRetry()
            // reflects the final retry decision: released vs. failed.
            WorkerMessageFailedEvent::class => ['onFailed', -100],
        ];
    }

    public function onSend(SendMessageToTransportsEvent $event): void
    {
        foreach ($event->getSenders() as $sender) {
            if ($sender instanceof CloudQueueTransport) {
                // A CloudQueueStamp picks the target queue (the ->onQueue()
                // equivalent), exactly as the transport's send() does; mirror it
                // here so the "queued" metric is attributed to the queue the
                // message actually lands on, not the container's default.
                $stamp = $event->getEnvelope()->last(CloudQueueStamp::class);

                $this->emitQueueEvent(
                    'queued',
                    $stamp instanceof CloudQueueStamp ? $stamp->queue : $this->config->queue,
                    $this->now(),
                );

                return;
            }
        }
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        if (! $this->isManaged($event->getEnvelope())) {
            return;
        }

        $startedAt = $this->now();

        // Stamp the start time onto the envelope so the matching handled/failed
        // event can report an accurate duration.
        $event->addStamps(new CloudProcessingStartedStamp($startedAt));

        $this->emitQueueEvent('started', $this->queue($event->getEnvelope()), $startedAt);
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $envelope = $event->getEnvelope();

        if (! $this->isManaged($envelope)) {
            return;
        }

        $finishedAt = $this->now();

        $this->emitQueueEvent('processed', $this->queue($envelope), $finishedAt, [
            'duration_ms' => $this->durationMs($envelope, $finishedAt),
        ]);
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $envelope = $event->getEnvelope();

        if (! $this->isManaged($envelope)) {
            return;
        }

        $finishedAt = $this->now();
        $queue = $this->queue($envelope);
        $willRetry = $event->willRetry();

        if ($willRetry) {
            $this->emitQueueEvent('released', $queue, $finishedAt, [
                'duration_ms' => $this->durationMs($envelope, $finishedAt),
            ]);

            return;
        }

        // Matches Laravel's FailedJobProvider::log, including emitting before
        // "failed". The payload must stay whole: dashboard retries decode it.
        $startedAt = $this->startedAt($envelope) ?? $finishedAt;
        $received = $envelope->last(CloudQueueReceivedStamp::class);
        $payload = $received?->body ?? '';
        $throwable = $event->getThrowable();

        $this->events->emit([
            '_cloud_event' => 'failed_job',
            'id' => (string) Uuid::v7(),
            'queue' => $queue,
            'started_at' => $startedAt->format('Y-m-d H:i:s.u'),
            // ApproximateReceiveCount, as Laravel's SqsJob::attempts().
            'attempts' => $received?->approximateReceiveCount ?? 1,
            'payload' => $payload,
            'exception_preview' => $this->exceptionPreview($throwable),
            'job_name' => $this->jobName($payload),
            'exception' => $this->utf8((string) $throwable),
        ]);

        $this->emitQueueEvent('failed', $queue, $finishedAt, [
            'duration_ms' => $this->durationMs($envelope, $finishedAt),
        ]);
    }

    /**
     * "Class: message in file:line", naming the handler's exception rather than
     * Messenger's HandlerFailedException wrapper.
     */
    private function exceptionPreview(\Throwable $throwable): string
    {
        if ($throwable instanceof HandlerFailedException) {
            $throwable = array_values($throwable->getWrappedExceptions())[0] ?? $throwable;
        }

        $location = $throwable->getFile().':'.$throwable->getLine();
        $message = $throwable->getMessage();

        $preview = $message !== ''
            ? $throwable::class.': '.$message.' in '.$location
            : $throwable::class.' in '.$location;

        return mb_substr($this->utf8($preview), 0, self::MAX_EXCEPTION_PREVIEW_CHARS, 'UTF-8');
    }

    private function jobName(string $payload): string
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) && is_string($decoded['displayName'] ?? null) ? $decoded['displayName'] : '';
    }

    /**
     * An encoding failure here would lose the failed job: the message is already deleted.
     */
    private function utf8(string $value): string
    {
        return (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    /**
     * Emit one queue-lifecycle event in Laravel Cloud's wire shape. The
     * "_cloud_event"/"queue" channel and the "Y-m-d H:i:s.u" timestamp format
     * are the dashboard's contract, so every lifecycle emit funnels through here
     * to keep them in lockstep.
     *
     * @param array<string, mixed> $extra
     */
    private function emitQueueEvent(string $type, string $queue, \DateTimeImmutable $at, array $extra = []): void
    {
        $this->events->emit([
            '_cloud_event' => 'queue',
            'timestamp' => $at->format('Y-m-d H:i:s.u'),
            'type' => $type,
            'queue' => $queue,
            ...$extra,
        ]);
    }

    private function isManaged(Envelope $envelope): bool
    {
        return $envelope->last(CloudQueueReceivedStamp::class) !== null;
    }

    private function queue(Envelope $envelope): string
    {
        $stamp = $envelope->last(CloudQueueReceivedStamp::class);

        return $stamp !== null
            ? $this->config->normalizeQueue($stamp->queueUrl)
            : $this->config->queue;
    }

    private function startedAt(Envelope $envelope): ?\DateTimeImmutable
    {
        return $envelope->last(CloudProcessingStartedStamp::class)?->startedAt;
    }

    private function durationMs(Envelope $envelope, \DateTimeImmutable $finishedAt): int
    {
        $startedAt = $this->startedAt($envelope);

        if ($startedAt === null) {
            return 0;
        }

        return (int) max(0, round(((float) $finishedAt->format('U.u') - (float) $startedAt->format('U.u')) * 1000));
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->utc);
    }
}
