<?php

namespace Fleetbase\FleetOps\Support\Telematics\Afaqy;

use Illuminate\Support\Carbon;

/** The webhook envelope is provisional until confirmed by a real AFAQY delivery. */
class Payload
{
    public static function unit(array $value): array
    {
        if (isset($value['data']) && is_array($value['data']) && !array_is_list($value['data'])) {
            $value = array_replace($value, $value['data']);
            unset($value['data']);
        }

        return $value;
    }

    public static function units(array $payload): array
    {
        if (isset($payload['_id']) || isset($payload['id'])) {
            $units = [$payload];
        } else {
            $units = $payload['data'] ?? $payload;
            if (!array_is_list($units)) {
                $units = [$units];
            }
        }
        if (!$units) {
            throw new \InvalidArgumentException('Empty position delivery.');
        }
        foreach ($units as &$unit) {
            if (!is_array($unit)) {
                throw new \InvalidArgumentException('Unsupported position envelope.');
            }
            $unit = self::unit($unit);
            if (!is_scalar($unit['_id'] ?? $unit['id'] ?? null) || !is_array($unit['last_update'] ?? null)
                || isset($unit['event']) || isset($unit['event_type'])) {
                throw new \InvalidArgumentException('Expected a unit identity and last_update position object.');
            }
        }

        return $units;
    }

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
