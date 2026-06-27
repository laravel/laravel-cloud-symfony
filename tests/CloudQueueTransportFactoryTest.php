<?php

namespace Laravel\Cloud\Symfony\Tests;

use Laravel\Cloud\Symfony\Queue\Agent\AgentClient;
use Laravel\Cloud\Symfony\Queue\ManagedQueueConfig;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueTransport;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueTransportFactory;
use Laravel\Cloud\Symfony\Queue\Sqs\SqsClientFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

class CloudQueueTransportFactoryTest extends TestCase
{
    /**
     * Every accepted spelling: singular/plural host, with and without a query
     * string, and with and without a trailing slash. parse_url() reduces them
     * all to the bare host, which is the only thing supports() looks at.
     *
     * @return iterable<string, array{string}>
     */
    public static function supportedDsns(): iterable
    {
        yield 'singular' => ['laravel-cloud://managed-queue'];
        yield 'plural' => ['laravel-cloud://managed-queues'];
        yield 'with query' => ['laravel-cloud://managed-queue?queue=test'];
        yield 'trailing slash with query' => ['laravel-cloud://managed-queue/?queue=test'];
        yield 'trailing slash' => ['laravel-cloud://managed-queue/'];
        yield 'plural with slash and query' => ['laravel-cloud://managed-queues/?queue=test&group=orders'];
        yield 'mixed case host' => ['laravel-cloud://Managed-Queue'];
    }

    #[DataProvider('supportedDsns')]
    public function testItSupportsEveryAcceptedDsnForm(string $dsn): void
    {
        $this->assertTrue($this->factory()->supports($dsn, []));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedDsns(): iterable
    {
        // Right scheme, wrong host — a typo must fail loudly, not silently map
        // to Managed Queues.
        yield 'host typo' => ['laravel-cloud://managed-queu'];
        yield 'unknown mechanic' => ['laravel-cloud://broadcast'];
        // The bare scheme has no host at all.
        yield 'scheme only' => ['laravel-cloud://'];
        // Foreign schemes belong to other transport factories.
        yield 'sqs' => ['sqs://default'];
        yield 'redis' => ['redis://localhost'];
        // The host must be a host, not a prefix of one elsewhere in the DSN.
        yield 'host as path' => ['laravel-cloud://elsewhere/managed-queue'];
    }

    #[DataProvider('unsupportedDsns')]
    public function testItRejectsDsnsItDoesNotOwn(string $dsn): void
    {
        $this->assertFalse($this->factory()->supports($dsn, []));
    }

    public function testCreateTransportBuildsACloudQueueTransport(): void
    {
        $transport = $this->factory()->createTransport(
            'laravel-cloud://managed-queue',
            [],
            new PhpSerializer(),
        );

        $this->assertInstanceOf(CloudQueueTransport::class, $transport);
    }

    private function factory(): CloudQueueTransportFactory
    {
        $config = ManagedQueueConfig::fromEnvironment(
            json_encode(['connection' => [
                'prefix' => 'https://sqs.us-east-2.amazonaws.com/036991040854',
                'suffix' => '-env-x',
                'queue' => 'default',
                'region' => 'us-east-2',
            ]]),
            '/unused.sock',
        );

        return new CloudQueueTransportFactory(
            $config,
            new SqsClientFactory($config),
            new AgentClient('/unused.sock'),
        );
    }
}
