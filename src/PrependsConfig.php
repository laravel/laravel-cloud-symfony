<?php

namespace Laravel\Cloud\Symfony;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * An optional capability for a {@see CloudFeature} that needs to contribute
 * configuration to *other* bundles before the container is built — e.g. to
 * register a Messenger transport on FrameworkBundle.
 *
 * The bundle calls {@see prepend()} during its prepend pass, but only for
 * features that are enabled. Implement this alongside {@see CloudFeature} when a
 * feature wants to ship ready-made config that apps would otherwise have to
 * write by hand.
 */
interface PrependsConfig
{
    /**
     * Contribute configuration to other extensions (typically `framework`).
     * Use {@see ContainerConfigurator::extension()} with `prepend: true` so the
     * app can still override anything this adds.
     */
    public function prepend(ContainerConfigurator $container, ContainerBuilder $builder): void;
}
