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

    public function testAgentAvailabilityReflectsSocketPresence(): void
    {
        $socket = tempnam(sys_get_temp_dir(), 'agent-sock');
        $present = ManagedQueueConfig::fromEnvironment(self::SAMPLE, $socket);
        $this->assertTrue($present->agentAvailable());
        unlink($socket);

        $absent = ManagedQueueConfig::fromEnvironment(self::SAMPLE, $socket);
        $this->assertFalse($absent->agentAvailable());
    }
}
