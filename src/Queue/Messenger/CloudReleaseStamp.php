<?php

namespace Laravel\Cloud\Symfony\Queue\Messenger;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Signals that a failed managed-queue message should be *released* back to SQS
 * for redelivery after $delaySeconds, rather than deleted.
 *
 * CloudRetrySubscriber decides retry-vs-terminal on WorkerMessageFailedEvent and
 * stamps this on the envelope; the transport's reject() then performs the actual
 * SQS-native release — the agent's "released" outcome, or ChangeMessageVisibility
 * on the direct path — instead of deleting the message. This mirrors Laravel's
 * $job->release($delay): the same message is redelivered by SQS, so retries ride
 * the visibility timeout (up to 12 hours) and never re-enter send(), unaffected
 * by the 15-minute per-message delay cap that applies to fresh dispatches.
 *
 * Non-sendable: it is an in-worker decision, never serialized onto a message.
 */
final class CloudReleaseStamp implements NonSendableStampInterface
{
    public function __construct(
        public readonly int $delaySeconds = 0,
    ) {
    }
}
