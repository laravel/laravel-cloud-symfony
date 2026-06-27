<?php

namespace Laravel\Cloud\Symfony\Queue\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Assigns the SQS message group ID for a message dispatched to a *standard*
 * managed queue, enabling SQS fair queues — the Symfony equivalent of Laravel's
 * $job->messageGroup() / ->onGroup():
 *
 *   $bus->dispatch(new ProcessOrder($order), [
 *       new CloudQueueStamp('orders'),
 *       new CloudMessageGroupStamp('customer-'.$order->customerId),
 *   ]);
 *
 * On a standard queue the group ID is purely a tenant key: SQS reads it to spread
 * consumer capacity fairly across groups so one noisy tenant can't starve the
 * others. It does NOT impose ordering — messages in the same group still process
 * in parallel at full standard-queue throughput — and it is NOT a deduplication
 * token (standard queues have no deduplication).
 *
 * For a FIFO (".fifo") queue the message group is instead an ordering scope and
 * lives on {@see CloudFifoStamp} alongside the deduplication ID; this stamp is
 * rejected there so the two queue models don't get crossed.
 *
 * Sendable on purpose (like CloudQueueStamp, unlike CloudFifoStamp): it travels
 * with the message so that when Messenger retries by re-sending a copy, the retry
 * keeps the same tenant attribution rather than losing its fair-queue group.
 */
final class CloudMessageGroupStamp implements StampInterface
{
    public function __construct(
        public readonly string $messageGroupId,
    ) {
    }
}
