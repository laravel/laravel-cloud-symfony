<?php

namespace Laravel\Cloud\Symfony;

use Laravel\Cloud\Symfony\Observability\Events;
use Laravel\Cloud\Symfony\Queue\QueueFeature;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Brings Laravel Cloud features to Symfony.
 *
 * This bundle is a thin host: it owns the shared Laravel Cloud platform
 * primitives — the in-container agent runtime socket and the observability log
 * socket / {@see Events} emitter — and delegates everything else to a list of
 * {@see CloudFeature}s. Each feature contributes its own `laravel_cloud.<name>`
 * config sub-tree and wires its own services, building on the shared primitives
 * via {@see PlatformContext}.
 *
 * Managed Queues ({@see QueueFeature}) is the first feature. To add another
 * Laravel Cloud capability, implement {@see CloudFeature} and list it in
 * {@see features()}.
 */
class LaravelCloudBundle extends AbstractBundle
{
    /**
     * The Laravel Cloud features this bundle wires up.
     *
     * @return list<CloudFeature>
     */
    protected function features(): array
    {
        return [
            new QueueFeature(),
        ];
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $children = $definition->rootNode()->children();

        // Shared platform primitives, available to every feature.
        $children
            ->scalarNode('agent_socket')
                ->info('Default path to the cloud-agent runtime socket. Overridable at runtime via LARAVEL_CLOUD_AGENT_SOCKET.')
                ->defaultValue('/tmp/cloud-agent.sock')
            ->end()
            ->scalarNode('log_socket')
                ->info('Default address of the cloud observability log socket. Overridable at runtime via LARAVEL_CLOUD_LOG_SOCKET.')
                ->defaultValue('unix:///tmp/cloud-init.sock')
            ->end();

        foreach ($this->features() as $feature) {
            $feature->configure($children);
        }
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $platform = $this->registerPlatform($config, $container);

        foreach ($this->features() as $feature) {
            $featureConfig = $config[$feature->name()] ?? [];

            // A feature is enabled unless it exposed an `enabled` flag and turned
            // it off (canBeDisabled/canBeEnabled set this key).
            if (($featureConfig['enabled'] ?? true) === false) {
                continue;
            }

            $feature->register($featureConfig, $container, $builder, $platform);
        }
    }

    /**
     * Wire the shared platform primitives and return the context features build on.
     *
     * @param  array<string, mixed>  $config
     */
    private function registerPlatform(array $config, ContainerConfigurator $container): PlatformContext
    {
        $parameters = $container->parameters();

        $parameters->set('laravel_cloud.agent_socket_default', $config['agent_socket']);
        // The socket path, with LARAVEL_CLOUD_AGENT_SOCKET overriding the
        // configured default. Resolved once and shared by every consumer.
        $parameters->set('laravel_cloud.agent_socket', '%env(default:laravel_cloud.agent_socket_default:LARAVEL_CLOUD_AGENT_SOCKET)%');

        $parameters->set('laravel_cloud.log_socket_default', $config['log_socket']);
        // The observability socket address, with LARAVEL_CLOUD_LOG_SOCKET
        // overriding the configured default. Matches Laravel's cloud socket.
        $parameters->set('laravel_cloud.log_socket', '%env(default:laravel_cloud.log_socket_default:LARAVEL_CLOUD_LOG_SOCKET)%');

        // The shared event emitter speaking Laravel Cloud's observability wire
        // protocol over the log socket. Features surface dashboard metrics
        // through this rather than opening their own socket.
        $container->services()
            ->set(Events::class)
            ->args(['%laravel_cloud.log_socket%']);

        return new PlatformContext('laravel_cloud.agent_socket', 'laravel_cloud.log_socket');
    }
}
