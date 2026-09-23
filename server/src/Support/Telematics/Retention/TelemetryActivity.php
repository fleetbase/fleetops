<?php

namespace Fleetbase\FleetOps\Support\Telematics\Retention;

use Spatie\Activitylog\ActivityLogger;

/**
 * Runs telemetry-driven writes with activity logging suppressed unless the
 * company opted in. Every poll saves the device, event, position and vehicle,
 * which otherwise writes several activity rows per device per minute.
 */
final class TelemetryActivity
{
    public static function run(?string $companyUuid, \Closure $callback): mixed
    {
        if (RetentionPolicy::forCompany($companyUuid)->logsTelemetryActivity()) {
            return $callback();
        }

        $logger = self::logger();
        if (!$logger) {
            return $callback();
        }

        return $logger->withoutLogs($callback);
    }

    /**
     * The logger is optional: an environment without the activity log package
     * bound (package tests, minimal consoles) must still ingest telemetry.
     */
    private static function logger(): ?ActivityLogger
    {
        try {
            return app(ActivityLogger::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
