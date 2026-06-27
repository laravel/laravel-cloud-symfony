<?php

namespace Laravel\Cloud\Symfony\Queue\Messenger;

use Laravel\Cloud\Symfony\Queue\Agent\AgentClient;
use Laravel\Cloud\Symfony\Queue\ManagedQueueConfig;
use Laravel\Cloud\Symfony\Queue\Sqs\SqsClientFactory;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Creates the managed-queue transport for DSNs of the form:
 *
 *   laravel-cloud://managed-queue
 *
 * The host segment selects the mechanic; both the singular and plural spelling
 * (managed-queue / managed-queues) map to Managed Queues, and a trailing slash
 * or query string is allowed (laravel-cloud://managed-queue/?queue=test). Only
 * the parsed host is significant — see {@see supports()}.
 *
 * The SQS coordinates come from LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG (parsed into
 * ManagedQueueConfig), so the DSN itself carries no connection detail — it just
 * selects this transport.
 *
 * Whether to receive via the in-container agent or directly from SQS is detected
 * automatically from the presence of the agent socket, so the same DSN does the
 * right thing on Laravel Cloud and in local development with no extra config.
 */
final class CloudQueueTransportFactory implements TransportFactoryInterface
{
    /**
     * Host segments that select the Managed Queues transport. The plural is
     * accepted as a forgiving alias for the canonical singular spelling.
     */
    private const HOSTS = ['managed-queue', 'managed-queues'];

    public function __construct(
        private readonly ManagedQueueConfig $config,
        private readonly SqsClientFactory $sqsClientFactory,
        private readonly AgentClient $agent,
    ) {
    }

    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return new CloudQueueTransport(
            $this->sqsClientFactory->create(),
            $this->agent,
            $this->config,
            $serializer,
            // Receive via the agent when its socket is present (inside a Laravel
            // Cloud container), otherwise straight from SQS (local dev).
            $this->config->agentAvailable(),
        );
    }

    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        // This bundle owns the laravel-cloud:// scheme; the host picks the
        // mechanic. parse_url() collapses every accepted form — query string,
        // trailing slash — down to the bare host, so we match on that alone.
        return str_starts_with($dsn, 'laravel-cloud://')
            && in_array(strtolower((string) parse_url($dsn, PHP_URL_HOST)), self::HOSTS, true);
    }
}
