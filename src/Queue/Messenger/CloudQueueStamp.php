<?php

namespace Laravel\Cloud\Symfony\Queue\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Selects which Laravel Cloud managed queue a message is dispatched to — the
 * Symfony equivalent of Laravel's ->onQueue('critical'):
 *
 *   $bus->dispatch(new ProcessReport($report), [new CloudQueueStamp('critical')]);
 *
 * Carry the logical queue *name* (e.g. "critical"), not a full SQS URL; the
 * transport assembles {prefix}/{queue}{suffix} from the injected managed-queue
 * config. Without this stamp a message goes to the default managed queue.
 *
 * Sendable on purpose (unlike CloudQueueReceivedStamp): it travels with the
 * message so that when Messenger retries by re-sending a copy, the retry lands
 * back on the same queue rather than falling through to the default.
 */
final class CloudQueueStamp implements StampInterface
{
    public function __construct(
        public readonly string $queue,
    ) {
    }
}
