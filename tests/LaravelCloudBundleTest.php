<?php

namespace Laravel\Cloud\Symfony\Tests;

use Laravel\Cloud\Symfony\LaravelCloudBundle;
use Laravel\Cloud\Symfony\Observability\Events;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueTransportFactory;
use Laravel\Cloud\Symfony\Queue\QueueEventSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

/**
 * The bundle is a feature host: it always wires the shared platform primitives
 * (the log socket Events emitter), then registers each enabled CloudFeature.
 * These tests pin that contract so adding/removing a feature stays a local
 * change to the feature list rather than bundle surgery.
 */
class LaravelCloudBundleTest extends TestCase
{
    public function testItRegistersTheSharedPlatformAndQueueFeatureByDefault(): void
    {
        $container = $this->buildContainer();

        // Shared platform primitive, owned by the bundle (not the feature).
        $this->assertTrue($container->hasDefinition(Events::class));

        // The queue feature's services are wired and tagged.
        $this->assertTrue($container->hasDefinition(CloudQueueTransportFactory::class));
        $this->assertTrue($container->getDefinition(CloudQueueTransportFactory::class)->hasTag('messenger.transport_factory'));
        $this->assertTrue($container->getDefinition(QueueEventSubscriber::class)->hasTag('kernel.event_subscriber'));
    }

    public function testDisablingTheQueueFeatureLeavesItsServicesOutButKeepsThePlatform(): void
    {
        $container = $this->buildContainer(['queue' => ['enabled' => false]]);

        // The shared platform is independent of any single feature.
        $this->assertTrue($container->hasDefinition(Events::class));

        // The disabled feature contributes nothing to the container.
        $this->assertFalse($container->hasDefinition(CloudQueueTransportFactory::class));
        $this->assertFalse($container->hasDefinition(QueueEventSubscriber::class));
    }

    public function testItPrependsAReadyMadeCloudTransportWhenTheQueueFeatureIsEnabled(): void
    {
        $transports = $this->prependedMessengerTransports();

        $this->assertSame('laravel-cloud://managed-queue', $transports['cloud'] ?? null);
    }

    public function testItDoesNotPrependTheCloudTransportWhenTheQueueFeatureIsDisabled(): void
    {
        $transports = $this->prependedMessengerTransports(['queue' => ['enabled' => false]]);

        $this->assertArrayNotHasKey('cloud', $transports);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function buildContainer(array $config = []): ContainerBuilder
    {
        $bundle = new LaravelCloudBundle();
        $container = new ContainerBuilder();

        $extension = $bundle->getContainerExtension();
        $this->assertNotNull($extension);

        $extension->load([$config], $container);

        return $container;
    }

    /**
     * Run the bundle's prepend pass with the given `laravel_cloud` config and
     * return the Messenger transports it contributed to FrameworkBundle.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function prependedMessengerTransports(array $config = []): array
    {
        $bundle = new LaravelCloudBundle();
        $container = new ContainerBuilder();
        $container->prependExtensionConfig('laravel_cloud', $config);

        // The bundle's extension implements PrependExtensionInterface and routes
        // prepend() to LaravelCloudBundle::prependExtension — the same path the
        // kernel takes when building the container.
        $extension = $bundle->getContainerExtension();
        $this->assertInstanceOf(PrependExtensionInterface::class, $extension);
        $extension->prepend($container);

        $framework = $container->getExtensionConfig('framework');

        return $framework[0]['messenger']['transports'] ?? [];
    }
}
