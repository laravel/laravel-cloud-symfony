<?php

namespace Laravel\Cloud\Symfony;

use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * A single Laravel Cloud capability that plugs into {@see LaravelCloudBundle}.
 *
 * The bundle owns the shared platform primitives — the in-container agent socket
 * and the observability log socket / event emitter — and exposes them via
 * {@see PlatformContext}. Each feature is otherwise self-contained: it declares
 * its own configuration sub-tree (`laravel_cloud.<name>`) and registers its own
 * services when enabled.
 *
 * Managed Queues ({@see Queue\QueueFeature}) is the first such feature. Adding a
 * new Laravel Cloud feature to Symfony means writing one of these and listing it
 * in {@see LaravelCloudBundle::features()} — no other part of the bundle needs to
 * change.
 */
interface CloudFeature
{
    /**
     * The config key this feature lives under: `laravel_cloud.<name>`. Also used
     * to label the feature in messages. Must be a stable, snake_case identifier
     * (e.g. "queue").
     */
    public function name(): string;

    /**
     * Contribute this feature's configuration. Implementations should add a
     * single array node named {@see name()} to the given builder — typically via
     * `$builder->arrayNode($this->name())->canBeDisabled()` so the feature can be
     * toggled with `laravel_cloud.<name>.enabled`.
     */
    public function configure(NodeBuilder $builder): void;

    /**
     * Register the feature's services. Only called when the feature is enabled.
     *
     * @param  array<string, mixed>  $config   This feature's own config slice (the contents of `laravel_cloud.<name>`).
     * @param  PlatformContext       $platform Shared Laravel Cloud primitives (agent socket, log socket, event emitter).
     */
    public function register(array $config, ContainerConfigurator $container, ContainerBuilder $builder, PlatformContext $platform): void;
}
