<?php

namespace Laravel\Cloud\Symfony\Queue\Messenger;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Sets the FIFO attributes for a message dispatched to a ".fifo" managed queue,
 * the Symfony equivalent of Laravel's $job->messageGroup / deduplicationId():
 *
 *   $bus->dispatch(new ProcessOrder($order), [
 *       new CloudQueueStamp('orders.fifo'),
 *       new CloudFifoStamp(messageGroupId: 'customer-'.$order->customerId),
 *   ]);
 *
 * Both fields are optional. When omitted the transport falls back to SQS-safe
 * defaults — the message group ID becomes the queue name (all of the queue's
 * messages are ordered together) and the deduplication ID a fresh unique value
 * (so nothing is dropped as a duplicate unless the queue itself uses
 * content-based deduplication), mirroring Laravel's SqsQueue defaults.
 *
 * Field names match Symfony's AmazonSqsFifoStamp so the concept reads the same
 * across transports. Only meaningful on a FIFO queue: the transport rejects this
 * stamp on a standard queue rather than silently ignoring it.
 *
 * Non-sendable on purpose: when Messenger retries by re-sending a copy, the
 * retry recomputes its FIFO attributes (notably a fresh deduplication ID) rather
 * than carrying a stale deduplication ID that SQS could drop as a duplicate.
 */
final class CloudFifoStamp implements NonSendableStampInterface
{
    public function __construct(
        public readonly ?string $messageGroupId = null,
        public readonly ?string $messageDeduplicationId = null,
    ) {
    }
}
