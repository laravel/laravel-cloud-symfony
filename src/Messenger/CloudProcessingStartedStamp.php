<?php

namespace Laravel\Cloud\Symfony\Messenger;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Records the instant a managed-queue message began processing.
 *
 * Added when the worker receives the message (WorkerMessageReceivedEvent) and
 * read back when it finishes (handled/failed) so the cloud "processed",
 * "released" and "failed" events can report an accurate duration_ms — mirroring
 * how Laravel's cloud Queue decorator times a job from pop() to finish.
 *
 * Non-sendable so it is never serialized onto a retry re-send; each fresh
 * delivery gets its own start time.
 */
final class CloudProcessingStartedStamp implements NonSendableStampInterface
{
    public function __construct(
        public readonly \DateTimeImmutable $startedAt,
    ) {
    }
}
