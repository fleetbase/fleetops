<?php

namespace Fleetbase\FleetOps\Support\Telematics\Telemetry;

use Illuminate\Support\Carbon;

/** Normalized telemetry validation and cross-transport signal identity. */
class Sample
{
    public static function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '' || is_bool($value) || (!is_string($value) && !is_numeric($value))) {
            return null;
        }
        try {
            $time = is_numeric($value)
                ? Carbon::createFromTimestampUTC((float) $value > 9999999999 ? (float) $value / 1000 : (float) $value)
                : Carbon::parse($value, 'UTC')->utc();

            return $time->toISOString();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function validPosition(array $event): bool
    {
        $lat  = data_get($event, 'location.lat');
        $lng  = data_get($event, 'location.lng');
        $time = self::timestamp($event['occurred_at'] ?? null);

        return is_numeric($lat) && is_numeric($lng) && is_finite((float) $lat) && is_finite((float) $lng)
            && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180
            && $time !== null && Carbon::parse($time)->lte(now()->addMinutes(5));
    }

    public static function signalKey(array $event): string
    {
        // Receipt/source/envelope differences must not change identity across poll and push.
        $signal = array_intersect_key($event, array_flip(['device_id', 'occurred_at', 'event_type', 'location', 'speed', 'heading', 'altitude', 'ignition']));
        ksort($signal);
        if (isset($signal['location'])) {
            $signal['location'] = array_map(fn ($v) => $v === null ? null : (float) $v, $signal['location']);
            ksort($signal['location']);
        }
        foreach (['speed', 'heading', 'altitude'] as $field) {
            if (isset($signal[$field])) {
                $signal[$field] = (float) $signal[$field];
            }
        }

        return hash('sha256', json_encode($signal, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
