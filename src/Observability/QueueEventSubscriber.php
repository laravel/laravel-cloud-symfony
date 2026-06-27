<?php

namespace Laravel\Cloud\Symfony\Observability;

use Laravel\Cloud\Symfony\ManagedQueueConfig;
use Laravel\Cloud\Symfony\Messenger\CloudProcessingStartedStamp;
use Laravel\Cloud\Symfony\Messenger\CloudQueueReceivedStamp;
use Laravel\Cloud\Symfony\Messenger\CloudQueueTransport;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
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
    /**
     * Byte budgets for the two large fields of a "failed_job" event.
     *
     * TODO: REMOVE THIS TRIMMING once the logging-collector reassembles CRI
     * partial log lines (see below). It is a temporary workaround — the caps,
     * the trimPayload()/truncate() helpers, and their call sites should all be
     * deleted and the full payload/exception emitted again.
     *
     * WHY THIS EXISTS — a workaround, not the real fix:
     *
     * Laravel Cloud's log pipeline ships container stdout to Kafka via a
     * Fluent Bit collector. containerd splits any stdout line longer than
     * 16 KiB into multiple CRI partial records ("P" … "F"). The collector's
     * tail input does not currently reassemble those partials (no
     * `multiline.parser cri`), so a log line over 16 KiB arrives as truncated,
     * invalid JSON. The router then can't read `_cloud_event` and drops the
     * record into the generic "customer-logs" topic instead of routing it to
     * "failed-job-events" — so the failed job never reaches the dashboard.
     *
     * Small queue lifecycle events (~120 bytes) are unaffected; only the fat
     * "failed_job" event (serialized envelope payload + full exception trace,
     * ~18 KiB) crosses the boundary. We keep the whole event comfortably under
     * 16 KiB here so it survives the pipeline as-is.
     *
     * THE REAL FIX belongs in the platform's logging collector: add
     * `multiline.parser cri` to the Fluent Bit `tail` input (configmap
     * `kube-system/logging-collector-config`) so CRI partial lines are
     * stitched back together. Once that lands, these caps can be relaxed or
     * removed. They are deliberately conservative for now.
     */
    private const MAX_EXCEPTION_BYTES = 4000;
    private const MAX_PAYLOAD_BODY_BYTES = 2000;

    public function __construct(
        private readonly Events $events,
        private readonly ManagedQueueConfig $config,
    ) {
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
                $this->events->emit([
                    '_cloud_event' => 'queue',
                    'timestamp' => $this->now()->format('Y-m-d H:i:s.u'),
                    'type' => 'queued',
                    'queue' => $this->config->queue,
                ]);

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

        $this->events->emit([
            '_cloud_event' => 'queue',
            'timestamp' => $startedAt->format('Y-m-d H:i:s.u'),
            'type' => 'started',
            'queue' => $this->queue($event->getEnvelope()),
        ]);
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $envelope = $event->getEnvelope();

        if (! $this->isManaged($envelope)) {
            return;
        }

        $finishedAt = $this->now();

        $this->events->emit([
            '_cloud_event' => 'queue',
            'timestamp' => $finishedAt->format('Y-m-d H:i:s.u'),
            'type' => 'processed',
            'queue' => $this->queue($envelope),
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

        $this->events->emit([
            '_cloud_event' => 'queue',
            'timestamp' => $finishedAt->format('Y-m-d H:i:s.u'),
            'type' => $willRetry ? 'released' : 'failed',
            'queue' => $queue,
            'duration_ms' => $this->durationMs($envelope, $finishedAt),
        ]);

        if ($willRetry) {
            return;
        }

        // A terminal failure is also recorded as a failed job, carrying the
        // payload and exception so it can be inspected (and, once payloads are
        // shipped whole, retried) from the Laravel Cloud dashboard.
        //
        // Both fields are trimmed to keep the event under the collector's CRI
        // line limit — see the MAX_* constants above for the full rationale.
        $startedAt = $this->startedAt($envelope) ?? $finishedAt;
        $received = $envelope->last(CloudQueueReceivedStamp::class);

        $exception = (string) mb_convert_encoding((string) $event->getThrowable(), 'UTF-8');

        $this->events->emit([
            '_cloud_event' => 'failed_job',
            'id' => (string) Uuid::v7(),
            'queue' => $queue,
            'started_at' => $startedAt->format('Y-m-d H:i:s.u'),
            'attempts' => RedeliveryStamp::getRetryCountFromEnvelope($envelope) + 1,
            'payload' => $this->trimPayload($received?->body ?? ''),
            'exception' => $this->truncate($exception, self::MAX_EXCEPTION_BYTES),
        ]);
    }

    /**
     * Shrink the failed-job payload so the event stays under the collector's
     * CRI line limit. The job's identifying metadata (uuid, displayName) is
     * preserved so the dashboard can still name the job; the bulky serialized
     * body — which balloons with an ErrorDetailsStamp stack trace baked in on
     * every retry — is truncated.
     *
     * Truncating the body means a retried job cannot be fully reconstructed
     * from this payload; that is an acceptable trade-off for the workaround and
     * is resolved by the real collector-side fix described on the constants.
     */
    private function trimPayload(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return $this->truncate($payload, self::MAX_PAYLOAD_BODY_BYTES);
        }

        $body = (string) ($decoded['body'] ?? '');
        $truncated = strlen($body) > self::MAX_PAYLOAD_BODY_BYTES;

        $compact = array_filter([
            'uuid' => $decoded['uuid'] ?? null,
            'displayName' => $decoded['displayName'] ?? null,
            'body' => $truncated ? substr($body, 0, self::MAX_PAYLOAD_BODY_BYTES) : $body,
            'body_truncated' => $truncated ?: null,
        ], static fn ($value) => $value !== null);

        return json_encode($compact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * Cut a string to at most $maxBytes bytes, appending an elision marker when
     * anything was removed. Multibyte-safe so we never split a UTF-8 sequence.
     */
    private function truncate(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $marker = sprintf("\n…[truncated %d bytes — see logging-collector CRI note]", strlen($value) - $maxBytes);

        return mb_strcut($value, 0, $maxBytes, 'UTF-8').$marker;
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
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
