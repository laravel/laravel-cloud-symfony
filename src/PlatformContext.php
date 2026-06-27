<?php

namespace Laravel\Cloud\Symfony;

use Laravel\Cloud\Symfony\Observability\Events;
use Symfony\Component\Config\Loader\ParamConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ReferenceConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The shared Laravel Cloud platform primitives every feature can build on,
 * handed to each {@see CloudFeature} when it registers its services.
 *
 * These are the container-level facts about running inside Laravel Cloud — the
 * in-container agent's runtime socket and the observability log socket — wired
 * once by {@see LaravelCloudBundle} and shared across all features so a feature
 * never has to know the underlying parameter names or service ids.
 */
final class PlatformContext
{
    public function __construct(
        private readonly string $agentSocketParameter,
        private readonly string $logSocketParameter,
    ) {
    }

    /**
     * Reference to the resolved cloud-agent runtime socket path
     * (LARAVEL_CLOUD_AGENT_SOCKET), for features that talk to the agent sidecar.
     */
    public function agentSocket(): ParamConfigurator
    {
        return param($this->agentSocketParameter);
    }

    /**
     * Reference to the resolved observability log socket address
     * (LARAVEL_CLOUD_LOG_SOCKET).
     */
    public function logSocket(): ParamConfigurator
    {
        return param($this->logSocketParameter);
    }

    /**
     * Reference to the shared {@see Events} emitter, for features that surface
     * metrics in the Laravel Cloud dashboard over the log socket.
     */
    public function events(): ReferenceConfigurator
    {
        return service(Events::class);
    }
}
