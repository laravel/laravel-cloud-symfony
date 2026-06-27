<?php

namespace Laravel\Cloud\Symfony\Sqs;

use Aws\Credentials\CredentialProvider;
use Aws\Sqs\SqsClient;
use Laravel\Cloud\Symfony\ManagedQueueConfig;

/**
 * Builds the SQS client used to push jobs (and, when running locally without
 * the cloud-agent, to receive them too).
 */
final class SqsClientFactory
{
    public function __construct(
        private readonly ManagedQueueConfig $config,
    ) {
    }

    public function create(): SqsClient
    {
        $args = [
            'version' => 'latest',
            'region' => $this->config->region ?? 'us-east-1',
        ];

        // Laravel Cloud runs on ECS and signals "use the container credential
        // provider" with credentials: "ecs". Any other value falls through to
        // the SDK's default credential chain (env vars, profiles, instance role).
        if ($this->config->usesEcsCredentials()) {
            $args['credentials'] = CredentialProvider::ecsCredentials();
        }

        return new SqsClient($args);
    }
}
