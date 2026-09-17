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

    /** Queue for connection-level jobs that predate durable polling (discovery and connection tests). */
    public static function pollQueue(): string
    {
        return config('telematics.telemetry.poll_queue') ?: 'default';
    }

    /** Route a telemetry broadcast to the configured queue without changing other callers of the event. */
    public static function withBroadcastQueue(object $event): object
    {
        if ($queue = config('telematics.telemetry.broadcast_queue')) {
            $event->broadcastQueue = $queue;
        }

        return $event;
    }

    public static function forConnection(Telematic $connection): array
    {
        return self::options(self::provider($connection));
    }
}
