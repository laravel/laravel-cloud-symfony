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
 * Whether to receive via the agent or directly from SQS is auto-detected from
 * the presence of the agent socket, but can be forced either way with the
 * "use_agent" transport option.
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
        $useAgent = array_key_exists('use_agent', $options)
            ? filter_var($options['use_agent'], FILTER_VALIDATE_BOOL)
            : $this->config->agentAvailable();

        return new CloudQueueTransport(
            $this->sqsClientFactory->create(),
            $this->agent,
            $this->config,
            $serializer,
            $useAgent,
        );
    }

    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'laravel-cloud://');
    }
}
