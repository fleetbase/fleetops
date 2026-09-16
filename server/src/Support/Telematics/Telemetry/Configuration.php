<?php

namespace Fleetbase\FleetOps\Support\Telematics\Telemetry;

use Fleetbase\FleetOps\Contracts\TelemetryProviderInterface;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;

class Configuration
{
    public static function provider(Telematic $connection): TelemetryProviderInterface
    {
        $provider = app(TelematicProviderRegistry::class)->resolve($connection->provider);
        if (!$provider instanceof TelemetryProviderInterface) {
            throw new \InvalidArgumentException('Provider does not support durable position ingestion.');
        }

        return $provider;
    }

    public static function options(TelemetryProviderInterface $provider): array
    {
        return array_replace(config('telematics.telemetry', []), $provider->telemetryOptions());
    }

    public static function forConnection(Telematic $connection): array
    {
        return self::options(self::provider($connection));
    }
}
