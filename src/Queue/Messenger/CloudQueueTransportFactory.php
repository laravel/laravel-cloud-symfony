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
        return str_starts_with($dsn, 'laravel-cloud://');
    }
}
