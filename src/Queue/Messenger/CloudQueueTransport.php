<?php

namespace Laravel\Cloud\Symfony\Queue\Messenger;

use Aws\Sqs\SqsClient;
use Laravel\Cloud\Symfony\Queue\Agent\AgentClient;
use Laravel\Cloud\Symfony\Queue\ManagedQueueConfig;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\QueueReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A Symfony Messenger transport for Laravel Cloud Managed Queues.
 *
 * Pushing always goes straight to SQS. Receiving goes through the in-container
 * cloud-agent (GET /next) when its socket is present; otherwise — running
 * locally with no agent — it falls back to receiving from SQS directly.
 *
 * Acknowledgement maps to the agent's "processed" outcome (it deletes the SQS
 * message). Rejection branches on whether the failure is retryable: when
 * CloudRetrySubscriber has stamped a CloudReleaseStamp, reject() releases the
 * message back to SQS for native redelivery (the agent's "released" outcome / a
 * visibility reset) after the backoff — exactly like Laravel's $job->release().
 * Otherwise the failure is terminal and reject() deletes the message, same as
 * ack(). Messenger's own re-send retry is disabled for this transport (see
 * QueueFeature), so retries never re-enter send().
 */
final class CloudQueueTransport implements TransportInterface, QueueReceiverInterface, MessageCountAwareInterface
{
    public function __construct(
        private readonly SqsClient $sqs,
        private readonly AgentClient $agent,
        private readonly ManagedQueueConfig $config,
        private readonly SerializerInterface $serializer,
        private readonly bool $useAgent,
    ) {
    }

    public function get(): iterable
    {
        return $this->useAgent ? $this->getFromAgent() : $this->getFromSqs();
    }

    /**
     * Receive from specific managed queues by name — what
     * `messenger:consume cloud --queues=critical` invokes.
     *
     * Inside a Laravel Cloud container the in-container agent is already pinned to
     * a single managed queue, so it serves the correct queue regardless of the
     * names requested here. The names take effect on the SQS-direct path (local
     * dev), where each is resolved to {prefix}/{name}{suffix} and polled in turn.
     *
     * @param string[] $queueNames
     *
     * @return iterable<Envelope>
     */
    public function getFromQueues(array $queueNames, int $fetchSize = 1): iterable
    {
        if ($this->useAgent) {
            return $this->getFromAgent();
        }

        foreach ($queueNames as $name) {
            $messages = [...$this->getFromSqs($this->config->queueUrlFor($name))];

            if ($messages !== []) {
                return $messages;
            }
        }

        return [];
    }

    public function ack(Envelope $envelope): void
    {
        $stamp = $this->receivedStamp($envelope);

        if ($stamp->fromAgent) {
            $this->agent->report($stamp->messageId, $stamp->receiptHandle, 'processed');

            return;
        }

        $this->deleteFromSqs($stamp);
    }

    public function reject(Envelope $envelope): void
    {
        // A CloudReleaseStamp means CloudRetrySubscriber judged this failure
        // retryable, so the message is released back to SQS for native
        // redelivery (visibility reset) rather than deleted — Laravel's
        // $job->release($delay). Without it the failure is terminal: remove the
        // message, same outcome as ack().
        if (($release = $envelope->last(CloudReleaseStamp::class)) instanceof CloudReleaseStamp) {
            $this->release($envelope, $release->delaySeconds);

            return;
        }

        $this->ack($envelope);
    }

    public function send(Envelope $envelope): Envelope
    {
        // A CloudQueueStamp routes to a named managed queue (the ->onQueue()
        // equivalent); without one the message goes to the default queue.
        $stamp = $envelope->last(CloudQueueStamp::class);
        $queue = $stamp instanceof CloudQueueStamp ? $stamp->queue : $this->config->queue;
        $queueUrl = $this->requireQueueUrl($this->config->queueUrlFor($queue));

        $args = [
            'QueueUrl' => $queueUrl,
            'MessageBody' => $this->encode($envelope),
        ];

        // A ".fifo" queue name marks a FIFO queue, which SQS treats specially: it
        // requires a message group ID, requires a deduplication ID (unless the
        // queue has content-based deduplication enabled), and rejects the
        // per-message delivery delay that standard queues allow.
        $fifo = $envelope->last(CloudFifoStamp::class);
        $group = $envelope->last(CloudMessageGroupStamp::class);

        if (ManagedQueueConfig::isFifo($queue)) {
            // On a FIFO queue the message group is an ordering scope set via
            // CloudFifoStamp. CloudMessageGroupStamp is the standard-queue (fair
            // queues) tool, so it has no meaning here — reject it rather than
            // quietly applying it to the wrong queue model.
            if ($group instanceof CloudMessageGroupStamp) {
                throw new TransportException(sprintf('A CloudMessageGroupStamp was applied to the FIFO queue "%s"; set a FIFO message group via CloudFifoStamp instead.', $queue));
            }

            // Group ID defaults to the queue name (all of its messages ordered
            // together); dedup ID defaults to a unique value so messages aren't
            // dropped as duplicates. Both mirror Laravel's SqsQueue defaults and
            // can be set explicitly via a CloudFifoStamp.
            $args['MessageGroupId'] = $fifo?->messageGroupId ?? $queue;
            $args['MessageDeduplicationId'] = $fifo?->messageDeduplicationId ?? (string) Uuid::v7();

            // FIFO queues don't support a per-message delay at all (only a
            // queue-level one), so fail loudly rather than delivering it
            // immediately behind the caller's back. (Retry backoff doesn't come
            // through here — it uses the visibility timeout; see CloudReleaseStamp.)
            if (($delayStamp = $envelope->last(DelayStamp::class)) instanceof DelayStamp && $delayStamp->getDelay() > 0) {
                throw new TransportException(sprintf('The FIFO queue "%s" does not support a per-message delay; remove the DelayStamp or dispatch to a standard queue.', $queue));
            }
        } else {
            if ($fifo instanceof CloudFifoStamp) {
                // FIFO attributes on a standard queue are meaningless; fail loudly
                // rather than silently dropping them.
                throw new TransportException(sprintf('A CloudFifoStamp was applied to the non-FIFO queue "%s"; FIFO attributes require a ".fifo" queue.', $queue));
            }

            // A message group on a standard queue enables SQS fair queues: SQS reads
            // it purely as a tenant key (no ordering, processed in parallel) and
            // spreads consumer capacity fairly across groups. It is never a
            // deduplication token — that attribute is FIFO-only — so a delay can
            // still apply alongside it.
            if ($group instanceof CloudMessageGroupStamp) {
                $args['MessageGroupId'] = $group->messageGroupId;
            }

            if (($delayStamp = $envelope->last(DelayStamp::class)) instanceof DelayStamp && $delayStamp->getDelay() > 0) {
                // SQS delivery delay is whole seconds and hard-capped at 15
                // minutes. A longer delay is unsatisfiable, so fail loudly rather
                // than silently delivering after 15 minutes (a job that must run
                // in an hour is a bug if it runs in 15 minutes instead). This is a
                // dispatch delay only — retry backoff uses the visibility timeout
                // (see CloudReleaseStamp), never send().
                $seconds = intdiv($delayStamp->getDelay(), 1000);

                if ($seconds > 900) {
                    throw new TransportException(sprintf('A message delay of %d seconds exceeds the SQS maximum of 900 seconds (15 minutes); SQS cannot delay a message beyond 15 minutes.', $seconds));
                }

                $args['DelaySeconds'] = $seconds;
            }
        }

        $result = $this->withSqs(fn () => $this->sqs->sendMessage($args));

        return $envelope->with(new TransportMessageIdStamp($result->get('MessageId')));
    }

    /**
     * Release a received message back to SQS for redelivery after $delaySeconds —
     * the SQS-native retry path. The agent's "released" outcome resets the
     * message's visibility, or, on the direct path, ChangeMessageVisibility does.
     * The message itself is never re-sent, so this is unaffected by the
     * 15-minute per-message delay cap and works identically on FIFO queues.
     * SQS caps a visibility timeout at 12 hours; the caller clamps to that.
     */
    private function release(Envelope $envelope, int $delaySeconds): void
    {
        $stamp = $this->receivedStamp($envelope);

        if ($stamp->fromAgent) {
            $this->agent->report($stamp->messageId, $stamp->receiptHandle, 'released', $delaySeconds);

            return;
        }

        if ($stamp->receiptHandle === null) {
            return;
        }

        $this->withSqs(fn () => $this->sqs->changeMessageVisibility([
            'QueueUrl' => $stamp->queueUrl,
            'ReceiptHandle' => $stamp->receiptHandle,
            'VisibilityTimeout' => $delaySeconds,
        ]));
    }

    public function getMessageCount(): int
    {
        $queueUrl = $this->requireQueueUrl($this->config->queueUrl);

        $result = $this->withSqs(fn () => $this->sqs->getQueueAttributes([
            'QueueUrl' => $queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessages'],
        ]));

        return (int) ($result->get('Attributes')['ApproximateNumberOfMessages'] ?? 0);
    }

    /**
     * @return iterable<Envelope>
     */
    private function getFromAgent(): iterable
    {
        $data = $this->agent->next();

        if (! (is_string($messageId = $data['messageId'] ?? null) && $messageId !== '')) {
            return [];
        }

        $receiptHandle = is_string($handle = $data['receiptHandle'] ?? null) ? $handle : null;
        $body = is_string($value = $data['body'] ?? null) ? $value : '';

        // The agent reports the real SQS queue URL the message came from; the
        // outcome must go back to that queue, not necessarily our push default.
        $queueUrl = is_string($url = $data['queueUrl'] ?? null) && $url !== '' ? $url : (string) $this->config->queueUrl;

        $receiveCount = $this->receiveCountFrom(is_array($attrs = $data['attributes'] ?? null) ? $attrs : []);

        return [$this->decode($body, $messageId, $receiptHandle, $queueUrl, fromAgent: true, receiveCount: $receiveCount)];
    }

    /**
     * @return iterable<Envelope>
     */
    private function getFromSqs(?string $queueUrl = null): iterable
    {
        $queueUrl = $this->requireQueueUrl($queueUrl ?? $this->config->queueUrl);

        $result = $this->withSqs(fn () => $this->sqs->receiveMessage([
            'QueueUrl' => $queueUrl,
            'MaxNumberOfMessages' => 1,
            'WaitTimeSeconds' => 20,
            'MessageAttributeNames' => ['All'],
            // ApproximateReceiveCount is the attempt counter for retries.
            'AttributeNames' => ['ApproximateReceiveCount'],
        ]));

        $message = $result->get('Messages')[0] ?? null;

        if ($message === null) {
            return [];
        }

        return [$this->decode(
            (string) ($message['Body'] ?? ''),
            (string) ($message['MessageId'] ?? ''),
            isset($message['ReceiptHandle']) ? (string) $message['ReceiptHandle'] : null,
            $queueUrl,
            fromAgent: false,
            receiveCount: $this->receiveCountFrom(is_array($attrs = $message['Attributes'] ?? null) ? $attrs : []),
        )];
    }

    /**
     * SQS's ApproximateReceiveCount for a message — how many times it has been
     * delivered — defaulting to 1 (the current delivery) when absent. Used as the
     * retry attempt counter, mirroring Laravel's SqsJob::attempts().
     *
     * @param array<string, mixed> $attributes
     */
    private function receiveCountFrom(array $attributes): int
    {
        return max(1, (int) ($attributes['ApproximateReceiveCount'] ?? 1));
    }

    private function decode(string $body, string $messageId, ?string $receiptHandle, string $queueUrl, bool $fromAgent, int $receiveCount = 1): Envelope
    {
        $stamp = new CloudQueueReceivedStamp($messageId, $receiptHandle, $queueUrl, $fromAgent, $body, $receiveCount);

        try {
            $envelope = $this->serializer->decode($this->unwrap($body));
        } catch (MessageDecodingFailedException $e) {
            // Drop the poison message so it isn't redelivered forever, then let
            // the worker route the failure as usual.
            $fromAgent
                ? $this->agent->report($messageId, $receiptHandle, 'processed')
                : $this->deleteFromSqs($stamp);

            throw $e;
        }

        return $envelope
            ->with($stamp)
            ->with(new TransportMessageIdStamp($messageId));
    }

    private function encode(Envelope $envelope): string
    {
        $encoded = $this->serializer->encode($envelope);

        // Keep the payload self-contained and SQS/JSON-safe: base64 the
        // serializer body (it may be native-serialized PHP) and carry the
        // headers alongside it. This round-trips identically whether the message
        // comes back from the agent or straight from SQS.
        //
        // The "uuid" and "displayName" keys mirror Laravel's queue payload shape
        // so that Laravel Cloud's managed-queue dashboard can identify a failed
        // job by name. They are observability-only metadata; decode() reads only
        // "body"/"headers", so the extra keys round-trip harmlessly (including
        // when the platform re-queues a failed job verbatim for retry).
        return json_encode([
            'uuid' => (string) Uuid::v7(),
            'displayName' => $envelope->getMessage()::class,
            'body' => base64_encode($encoded['body'] ?? ''),
            'headers' => $encoded['headers'] ?? [],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{body: string, headers: array<string, mixed>}
     */
    private function unwrap(string $body): array
    {
        $wrapper = json_decode($body, true);

        if (! is_array($wrapper) || ! isset($wrapper['body'])) {
            throw new MessageDecodingFailedException('The managed queue message body is not in the expected envelope format.');
        }

        return [
            'body' => (string) base64_decode((string) $wrapper['body'], true),
            'headers' => is_array($wrapper['headers'] ?? null) ? $wrapper['headers'] : [],
        ];
    }

    private function deleteFromSqs(CloudQueueReceivedStamp $stamp): void
    {
        if ($stamp->receiptHandle === null) {
            return;
        }

        $this->withSqs(fn () => $this->sqs->deleteMessage([
            'QueueUrl' => $stamp->queueUrl,
            'ReceiptHandle' => $stamp->receiptHandle,
        ]));
    }

    /**
     * Resolve a queue URL, or fail with one actionable message everywhere a
     * managed queue is required but none is configured (running locally with no
     * LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG).
     */
    private function requireQueueUrl(?string $queueUrl): string
    {
        if ($queueUrl === null) {
            throw new TransportException('No Laravel Cloud managed queue is configured (LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG is missing).');
        }

        return $queueUrl;
    }

    /**
     * Run an SQS call, translating any AWS/SDK failure into a Messenger
     * TransportException so every SQS touchpoint surfaces faults identically.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function withSqs(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    private function receivedStamp(Envelope $envelope): CloudQueueReceivedStamp
    {
        $stamp = $envelope->last(CloudQueueReceivedStamp::class);

        if (! $stamp instanceof CloudQueueReceivedStamp) {
            throw new TransportException('Cannot acknowledge a message that did not come from the Laravel Cloud managed queue transport.');
        }

        return $stamp;
    }
}
