<?php

namespace Laravel\Cloud\Symfony\Queue;

/**
 * Parses the LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG blob injected by Laravel Cloud
 * and exposes the bits the transport needs: the SQS queue URL, region,
 * credential mode, and the in-container agent's runtime socket path.
 *
 * The injected JSON looks like:
 *
 *   {
 *     "driver": "cloud",
 *     "queue": "default",
 *     "connection": {
 *       "driver": "sqs",
 *       "prefix": "https://sqs.us-east-2.amazonaws.com/036991040854",
 *       "suffix": "-env-a21e326b-...",
 *       "queue": "default",
 *       "region": "us-east-2",
 *       "credentials": "ecs"
 *     }
 *   }
 */
final class ManagedQueueConfig
{
    public function __construct(
        public readonly ?string $queueUrl,
        public readonly ?string $region,
        public readonly string $credentials,
        public readonly string $queue,
        public readonly string $agentSocket,
        public readonly string $prefix = '',
        public readonly string $suffix = '',
    ) {
    }

    /**
     * Build the config from the raw env values. Both arguments may be null/empty
     * (e.g. running locally with no managed queue) — the resulting config simply
     * reports itself as not configured rather than throwing.
     */
    public static function fromEnvironment(?string $json, ?string $agentSocket): self
    {
        $data = [];

        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);
            $data = is_array($decoded) ? $decoded : [];
        }

        $connection = is_array($data['connection'] ?? null) ? $data['connection'] : [];

        // The agent message reports the real queue URL it came from, but for
        // pushing we build it the same way Laravel's SqsQueue does:
        // {prefix}/{queue}{suffix}.
        $prefix = rtrim((string) ($connection['prefix'] ?? ''), '/');
        $queue = (string) ($connection['queue'] ?? $data['queue'] ?? 'default');
        $suffix = (string) ($connection['suffix'] ?? '');

        return new self(
            queueUrl: $prefix !== '' ? sprintf('%s/%s%s', $prefix, $queue, $suffix) : null,
            region: isset($connection['region']) ? (string) $connection['region'] : null,
            credentials: (string) ($connection['credentials'] ?? 'default'),
            queue: $queue,
            agentSocket: $agentSocket !== null && $agentSocket !== '' ? $agentSocket : '/tmp/cloud-agent.sock',
            prefix: $prefix,
            suffix: $suffix,
        );
    }

    /**
     * Reduce a full SQS queue URL (or name) back to its logical queue name by
     * stripping the configured prefix and suffix — the same name the Laravel
     * Cloud dashboard groups metrics under.
     *
     * Mirrors Laravel\Foundation\Cloud\Queue::normalizeQueue: the prefix is
     * removed from the front, and the suffix from the end, taking care to keep a
     * trailing ".fifo" in place for FIFO queues.
     */
    public function normalizeQueue(?string $queue): string
    {
        $queue = (string) $queue;

        if ($this->prefix !== '' && str_starts_with($queue, $this->prefix.'/')) {
            $queue = substr($queue, strlen($this->prefix) + 1);
        }

        if ($this->suffix !== '') {
            if (str_ends_with($queue, '.fifo')) {
                $base = substr($queue, 0, -strlen('.fifo'));

                if (str_ends_with($base, $this->suffix)) {
                    $queue = substr($base, 0, -strlen($this->suffix)).'.fifo';
                }
            } elseif (str_ends_with($queue, $this->suffix)) {
                $queue = substr($queue, 0, -strlen($this->suffix));
            }
        }

        return $queue;
    }

    /**
     * Whether a managed queue has actually been provisioned (i.e. we have a queue
     * URL to push to). False when running outside Laravel Cloud with no config.
     */
    public function isConfigured(): bool
    {
        return $this->queueUrl !== null;
    }

    /**
     * Whether the in-container cloud-agent is reachable. The agent is a sidecar
     * that only exists inside a Laravel Cloud container, so its absence (no
     * socket on disk) is how we detect "running locally" and fall back to
     * talking to SQS directly.
     */
    public function agentAvailable(): bool
    {
        return $this->agentSocket !== '' && @file_exists($this->agentSocket);
    }

    /**
     * Whether SQS credentials should come from the ECS container credential
     * provider (the relative-URI endpoint) rather than the default chain.
     */
    public function usesEcsCredentials(): bool
    {
        return $this->credentials === 'ecs';
    }
}
