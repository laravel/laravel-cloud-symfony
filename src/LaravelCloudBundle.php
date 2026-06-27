<?php

namespace Laravel\Cloud\Symfony;

use Laravel\Cloud\Symfony\Agent\AgentClient;
use Laravel\Cloud\Symfony\Messenger\CloudQueueTransportFactory;
use Laravel\Cloud\Symfony\Observability\Events;
use Laravel\Cloud\Symfony\Observability\QueueEventSubscriber;
use Laravel\Cloud\Symfony\Sqs\SqsClientFactory;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\env;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Wires Laravel Cloud Managed Queues into Symfony: a Messenger transport factory
 * (laravel-cloud://) backed by the parsed managed-queue config, an SQS client,
 * the cloud-agent runtime client, and the observability subscriber that emits
 * queue metrics to the Cloud dashboard over the log socket.
 */
class LaravelCloudBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('agent_socket')
                    ->info('Default path to the cloud-agent runtime socket. Overridable at runtime via LARAVEL_CLOUD_AGENT_SOCKET.')
                    ->defaultValue('/tmp/cloud-agent.sock')
                ->end()
                ->scalarNode('log_socket')
                    ->info('Default address of the cloud observability log socket. Overridable at runtime via LARAVEL_CLOUD_LOG_SOCKET.')
                    ->defaultValue('unix:///tmp/cloud-init.sock')
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $parameters = $container->parameters();
        $parameters->set('laravel_cloud.agent_socket_default', $config['agent_socket']);
        // The socket path, with LARAVEL_CLOUD_AGENT_SOCKET overriding the
        // configured default. Resolved once and shared by both consumers.
        $parameters->set('laravel_cloud.agent_socket', '%env(default:laravel_cloud.agent_socket_default:LARAVEL_CLOUD_AGENT_SOCKET)%');

        $parameters->set('laravel_cloud.log_socket_default', $config['log_socket']);
        // The observability socket address, with LARAVEL_CLOUD_LOG_SOCKET
        // overriding the configured default. Matches Laravel's cloud socket.
        $parameters->set('laravel_cloud.log_socket', '%env(default:laravel_cloud.log_socket_default:LARAVEL_CLOUD_LOG_SOCKET)%');

        $services = $container->services();

        // Parsed managed-queue config. Both env vars are read with a default so
        // the container still boots locally with no managed queue provisioned.
        $services->set(ManagedQueueConfig::class)
            ->factory([ManagedQueueConfig::class, 'fromEnvironment'])
            ->args([
                env('default::LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG'),
                param('laravel_cloud.agent_socket'),
            ]);

        $services->set(SqsClientFactory::class)
            ->args([service(ManagedQueueConfig::class)]);

        $services->set(AgentClient::class)
            ->args([param('laravel_cloud.agent_socket')]);

        $services->set(CloudQueueTransportFactory::class)
            ->args([
                service(ManagedQueueConfig::class),
                service(SqsClientFactory::class),
                service(AgentClient::class),
            ])
            ->tag('messenger.transport_factory');

        // Observability: a socket emitter speaking Laravel Cloud's event
        // protocol, driven by a Messenger subscriber that maps the worker
        // lifecycle onto queue/failed_job events for the Cloud dashboard.
        $services->set(Events::class)
            ->args([param('laravel_cloud.log_socket')]);

        $services->set(QueueEventSubscriber::class)
            ->args([
                service(Events::class),
                service(ManagedQueueConfig::class),
            ])
            ->tag('kernel.event_subscriber');
    }
}
