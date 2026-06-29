<?php

namespace Laravel\Cloud\Symfony\Tests;

use Laravel\Cloud\Symfony\Queue\ManagedQueueConfig;
use PHPUnit\Framework\TestCase;

class ManagedQueueConfigTest extends TestCase
{
    private const SAMPLE = '{"driver":"cloud","queue":"default","connection":{"driver":"sqs","prefix":"https:\/\/sqs.us-east-2.amazonaws.com\/036991040854","suffix":"-env-a21e326b-405d-433e-a320-94a7e6b45dea","queue":"default","region":"us-east-2","credentials":"ecs"}}';

    public function testItBuildsTheQueueUrlFromPrefixQueueAndSuffix(): void
    {
        $config = ManagedQueueConfig::fromEnvironment(self::SAMPLE, '/tmp/cloud-agent.sock');

        $this->assertSame(
            'https://sqs.us-east-2.amazonaws.com/036991040854/default-env-a21e326b-405d-433e-a320-94a7e6b45dea',
            $config->queueUrl,
        );
        $this->assertSame('us-east-2', $config->region);
        $this->assertSame('default', $config->queue);
        $this->assertTrue($config->isConfigured());
        $this->assertTrue($config->usesEcsCredentials());
    }

    public function testQueueUrlForBuildsANamedQueueUrlFromPrefixAndSuffix(): void
    {
        $config = ManagedQueueConfig::fromEnvironment(self::SAMPLE, '/tmp/cloud-agent.sock');

        $this->assertSame(
            'https://sqs.us-east-2.amazonaws.com/036991040854/critical-env-a21e326b-405d-433e-a320-94a7e6b45dea',
            $config->queueUrlFor('critical'),
        );
        // The default queue resolves to the same URL exposed as $queueUrl.
        $this->assertSame($config->queueUrl, $config->queueUrlFor('default'));
    }

    public function testQueueUrlForIsNullWhenNoQueueIsConfigured(): void
    {
        $config = ManagedQueueConfig::fromEnvironment(null, null);

        $this->assertNull($config->queueUrlFor('critical'));
    }

    public function testQueueUrlForKeepsTheFifoMarkerAfterTheSuffix(): void
    {
        $config = ManagedQueueConfig::fromEnvironment(self::SAMPLE, '/tmp/cloud-agent.sock');

        // The per-environment suffix is inserted before ".fifo", not after it —
        // {prefix}/{base}{suffix}.fifo — matching Laravel's SqsQueue::suffixQueue.
        $this->assertSame(
            'https://sqs.us-east-2.amazonaws.com/036991040854/orders-env-a21e326b-405d-433e-a320-94a7e6b45dea.fifo',
            $config->queueUrlFor('orders.fifo'),
        );
    }

    public function testTheDefaultQueueUrlIsAlsoFifoAware(): void
    {
        $json = '{"connection":{"prefix":"https://sqs.us-east-2.amazonaws.com/036991040854","suffix":"-env-x","queue":"default.fifo","region":"us-east-2"}}';

        $config = ManagedQueueConfig::fromEnvironment($json, null);

        $this->assertSame(
            'https://sqs.us-east-2.amazonaws.com/036991040854/default-env-x.fifo',
            $config->queueUrl,
        );
    }

    public function testNormalizeQueueRoundTripsAFifoQueueUrl(): void
    {
        $config = ManagedQueueConfig::fromEnvironment(self::SAMPLE, '/tmp/cloud-agent.sock');

        // The full FIFO URL we build normalizes back to the logical ".fifo" name
        // the dashboard groups metrics under — the inverse of queueUrlFor().
        $url = $config->queueUrlFor('orders.fifo');

        $this->assertSame('orders.fifo', $config->normalizeQueue($url));
    }

    public function testNormalizeQueueStripsTheSuffixFromAStandardQueueUrl(): void
    {
        $config = ManagedQueueConfig::fromEnvironment(self::SAMPLE, '/tmp/cloud-agent.sock');

        $this->assertSame('critical', $config->normalizeQueue($config->queueUrlFor('critical')));
    }

    public function testItIsNotConfiguredWithoutAConnection(): void
    {
        $config = ManagedQueueConfig::fromEnvironment(null, null);

        $this->assertNull($config->queueUrl);
        $this->assertFalse($config->isConfigured());
        $this->assertFalse($config->usesEcsCredentials());
        // Falls back to the conventional default socket path.
        $this->assertSame('/tmp/cloud-agent.sock', $config->agentSocket);
    }

    public function testItTreatsMalformedJsonAsUnconfigured(): void
    {
        $config = ManagedQueueConfig::fromEnvironment('not json', null);

        $this->assertFalse($config->isConfigured());
    }

    public function testNonEcsCredentialsFallBackToTheDefaultChain(): void
    {
        $json = '{"connection":{"prefix":"https://sqs.eu-west-1.amazonaws.com/123","queue":"emails","region":"eu-west-1"}}';

        $config = ManagedQueueConfig::fromEnvironment($json, null);

        $this->assertSame('https://sqs.eu-west-1.amazonaws.com/123/emails', $config->queueUrl);
        $this->assertSame('default', $config->credentials);
        $this->assertFalse($config->usesEcsCredentials());
    }

    public function testAgentAvailabilityReflectsTheEnabledFlag(): void
    {
        // The agent is used because Laravel Cloud says so — not because a socket
        // happens to exist on disk. A non-existent socket path is irrelevant.
        $enabled = ManagedQueueConfig::fromEnvironment(
            '{"agent":{"enabled":true,"socket":"/does/not/exist.sock"}}',
            null,
        );
        $this->assertTrue($enabled->agentAvailable());

        $disabled = ManagedQueueConfig::fromEnvironment(
            '{"agent":{"enabled":false,"socket":"/does/not/exist.sock"}}',
            null,
        );
        $this->assertFalse($disabled->agentAvailable());
    }

    public function testAgentIsDisabledWhenNoAgentSliceIsPresent(): void
    {
        // Running locally with no managed-queue config: the flag defaults off so
        // the transport receives straight from SQS.
        $config = ManagedQueueConfig::fromEnvironment(self::SAMPLE, '/tmp/cloud-agent.sock');

        $this->assertFalse($config->agentEnabled);
        $this->assertFalse($config->agentAvailable());
    }

    public function testAgentSocketComesFromTheAgentSlice(): void
    {
        $config = ManagedQueueConfig::fromEnvironment(
            '{"agent":{"enabled":true,"socket":"/run/cloud-agent.sock"}}',
            null,
        );

        $this->assertSame('/run/cloud-agent.sock', $config->agentSocket);
    }

    public function testAgentSocketFallsBackToTheDefaultWhenMissingOrNull(): void
    {
        // Enabled, but no socket given in the slice and no override passed in:
        // fall back to the conventional default path so the agent can be dialed.
        $missing = ManagedQueueConfig::fromEnvironment('{"agent":{"enabled":true}}', null);
        $this->assertSame('/tmp/cloud-agent.sock', $missing->agentSocket);

        $null = ManagedQueueConfig::fromEnvironment('{"agent":{"enabled":true,"socket":null}}', null);
        $this->assertSame('/tmp/cloud-agent.sock', $null->agentSocket);
    }

    public function testAgentSocketFallsBackToTheConfiguredOverrideBeforeTheDefault(): void
    {
        // With no socket in the slice, the bundle's LARAVEL_CLOUD_AGENT_SOCKET
        // override (passed in as the second argument) wins over the hard default.
        $config = ManagedQueueConfig::fromEnvironment('{"agent":{"enabled":true}}', '/custom/agent.sock');

        $this->assertSame('/custom/agent.sock', $config->agentSocket);
    }
}
