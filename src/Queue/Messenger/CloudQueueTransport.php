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
 * message). Rejection maps to "processed" as well: Messenger implements retries
 * by re-sending a fresh copy and then rejecting the original, so a rejected
 * message must be removed from the queue — exactly as Symfony's own SQS
 * transport does. (The agent's "released" outcome is reserved for SQS-native
 * redelivery, which Messenger's re-send retry model does not use.)
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
        // Symfony retries by re-sending a copy and rejecting the original, so a
        // rejected message is finished and must leave the queue — same outcome
        // as ack(). See the class docblock.
        $this->ack($envelope);
    }

    public function send(Envelope $envelope): Envelope
    {
        // A CloudQueueStamp routes to a named managed queue (the ->onQueue()
        // equivalent); without one the message goes to the default queue.
        $stamp = $envelope->last(CloudQueueStamp::class);
        $queueUrl = $stamp instanceof CloudQueueStamp
            ? $this->config->queueUrlFor($stamp->queue)
            : $this->config->queueUrl;

        if ($queueUrl === null) {
            throw new TransportException('No Laravel Cloud managed queue is configured (LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG is missing).');
        }

        $args = [
            'QueueUrl' => $queueUrl,
            'MessageBody' => $this->encode($envelope),
        ];

        if (($delayStamp = $envelope->last(DelayStamp::class)) instanceof DelayStamp && $delayStamp->getDelay() > 0) {
            // SQS delivery delay is whole seconds, capped at 15 minutes.
            $args['DelaySeconds'] = min(900, intdiv($delayStamp->getDelay(), 1000));
        }

        try {
            $result = $this->sqs->sendMessage($args);
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        return $envelope->with(new TransportMessageIdStamp($result->get('MessageId')));
    }

    public function getMessageCount(): int
    {
        $result = $this->sqs->getQueueAttributes([
            'QueueUrl' => $this->config->queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessages'],
        ]);

        return (int) ($result->get('Attributes')['ApproximateNumberOfMessages'] ?? 0);
    }

    /**
     * @return iterable<Envelope>
     */
    private function getFromAgent(): iterable
    {
        $data = $this->agent->next();

        if (! (is_array($data) && is_string($messageId = $data['messageId'] ?? null) && $messageId !== '')) {
            return [];
        }

        $receiptHandle = is_string($handle = $data['receiptHandle'] ?? null) ? $handle : null;
        $body = is_string($value = $data['body'] ?? null) ? $value : '';

        // The agent reports the real SQS queue URL the message came from; the
        // outcome must go back to that queue, not necessarily our push default.
        $queueUrl = is_string($url = $data['queueUrl'] ?? null) && $url !== '' ? $url : (string) $this->config->queueUrl;

        return [$this->decode($body, $messageId, $receiptHandle, $queueUrl, fromAgent: true)];
    }

    /**
     * @return iterable<Envelope>
     */
    private function getFromSqs(?string $queueUrl = null): iterable
    {
        $queueUrl ??= $this->config->queueUrl;

        if ($queueUrl === null) {
            throw new TransportException('No Laravel Cloud managed queue is configured (LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG is missing).');
        }

        try {
            $result = $this->sqs->receiveMessage([
                'QueueUrl' => $queueUrl,
                'MaxNumberOfMessages' => 1,
                'WaitTimeSeconds' => 20,
                'MessageAttributeNames' => ['All'],
            ]);
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

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
        )];
    }

    private function decode(string $body, string $messageId, ?string $receiptHandle, string $queueUrl, bool $fromAgent): Envelope
    {
        $stamp = new CloudQueueReceivedStamp($messageId, $receiptHandle, $queueUrl, $fromAgent, $body);

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

        try {
            $this->sqs->deleteMessage([
                'QueueUrl' => $stamp->queueUrl,
                'ReceiptHandle' => $stamp->receiptHandle,
            ]);
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
