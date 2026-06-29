<?php

namespace Laravel\Cloud\Symfony\Queue;

use Laravel\Cloud\Symfony\CloudFeature;
use Laravel\Cloud\Symfony\PlatformContext;
use Laravel\Cloud\Symfony\PrependsConfig;
use Laravel\Cloud\Symfony\Queue\Agent\AgentClient;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueTransportFactory;
use Laravel\Cloud\Symfony\Queue\Sqs\SqsClientFactory;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\env;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The Laravel Cloud Managed Queues feature for Symfony.
 *
 * Wires a Messenger transport factory (laravel-cloud://) backed by the parsed
 * managed-queue config, an SQS client, the cloud-agent runtime client, and the
 * observability subscriber that emits queue metrics to the Cloud dashboard over
 * the shared log socket.
 *
 * Inside a Laravel Cloud container jobs are pushed straight to SQS but received
 * from the in-container cloud-agent sidecar; when that socket is absent (local
 * development) it transparently falls back to receiving from SQS directly.
 */
final class QueueFeature implements CloudFeature, PrependsConfig
{
    /**
     * The DSN that selects this feature's managed-queue transport. Carries no
     * connection detail itself — see {@see CloudQueueTransportFactory}.
     */
    private const TRANSPORT_DSN = 'laravel-cloud://managed-queue';

    public function name(): string
    {
        return 'queue';
    }

    /**
     * Ship a ready-made `cloud` Messenger transport that always points at
     * Laravel Cloud Managed Queues, so an app can route to it without declaring
     * a transport or wiring up a DSN:
     *
     *   framework:
     *       messenger:
     *           routing:
     *               '*': cloud
     *
     * Prepended (rather than loaded) so an app can still redefine `cloud`
     * itself if it ever needs to.
     */
    public function prepend(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->extension('framework', [
            'messenger' => [
                'transports' => [
                    'cloud' => [
                        'dsn' => self::TRANSPORT_DSN,
                        // Retries use Messenger's stock retry_strategy as-is — the
                        // transport bridges SQS's ApproximateReceiveCount into a
                        // RedeliveryStamp so the default strategy counts correctly,
                        // and turns each re-send into a visibility reset rather than
                        // a duplicate (see CloudQueueTransport). Set
                        // framework.messenger.transports.cloud.retry_strategy.* to
                        // tune max_retries / delay / multiplier as on any transport.
                    ],
                ],
            ],
        ], prepend: true);
    }

    public function configure(NodeBuilder $builder): void
    {
        // Managed Queues is on by default; set `laravel_cloud.queue.enabled:
        // false` to leave the transport, SQS client and metrics out entirely.
        //
        // Retry tuning is not configured here: it rides Messenger's standard
        // `framework.messenger.transports.cloud.retry_strategy` (max_retries,
        // delay, multiplier, max_delay), which the transport honours natively.
        $builder
            ->arrayNode($this->name())
                ->info('Laravel Cloud Managed Queues, on top of Symfony Messenger.')
                ->canBeDisabled()
            ->end();
    }

    public function register(array $config, ContainerConfigurator $container, ContainerBuilder $builder, PlatformContext $platform): void
    {
        $services = $container->services();

        // Parsed managed-queue config. The env var is read with a default so the
        // container still boots locally with no managed queue provisioned.
        $services->set(ManagedQueueConfig::class)
            ->factory([ManagedQueueConfig::class, 'fromEnvironment'])
            ->args([
                env('default::LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG'),
                $platform->agentSocket(),
            ]);

        $services->set(SqsClientFactory::class)
            ->args([service(ManagedQueueConfig::class)]);

        // The agent socket now rides in the managed-queue config (with the
        // LARAVEL_CLOUD_AGENT_SOCKET override folded in there as a fallback), so
        // the client and the receive-path decision share one source of truth.
        $services->set(AgentClient::class)
            ->args([service(ManagedQueueConfig::class)]);

        $services->set(CloudQueueTransportFactory::class)
            ->args([
                service(ManagedQueueConfig::class),
                service(SqsClientFactory::class),
                service(AgentClient::class),
            ])
            ->tag('messenger.transport_factory');

        // Observability: a Messenger subscriber that maps the worker lifecycle
        // onto queue/failed_job events, emitted through the shared log socket.
        $services->set(QueueEventSubscriber::class)
            ->args([
                $platform->events(),
                service(ManagedQueueConfig::class),
            ])
            ->tag('kernel.event_subscriber');
    }
}
