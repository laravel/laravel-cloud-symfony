<?php

namespace Laravel\Cloud\Symfony\Queue\Messenger;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Carries the SQS coordinates of a received message so ack()/reject() know where
 * to report the outcome. Marked non-sendable so it is never serialized onto a
 * retry re-send (a fresh delivery gets its own coordinates).
 *
 * It also retains the raw message body so that, on a terminal failure, the
 * cloud "failed_job" event can report the exact payload the queue carried —
 * exactly as Laravel logs the raw payload for a failed job.
 *
 * $approximateReceiveCount carries SQS's ApproximateReceiveCount — how many times
 * this message has been delivered. The transport reconstructs a RedeliveryStamp
 * from it on receive so Messenger's stock retry strategy can count attempts, and
 * the failed-job event reports it directly — the same source as Laravel's
 * SqsJob::attempts().
 */
final class CloudQueueReceivedStamp implements NonSendableStampInterface
{
    public function __construct(
        public readonly string $messageId,
        public readonly ?string $receiptHandle,
        public readonly string $queueUrl,
        public readonly bool $fromAgent,
        public readonly string $body = '',
        public readonly int $approximateReceiveCount = 1,
    ) {
    }
}
