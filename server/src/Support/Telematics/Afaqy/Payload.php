<?php

namespace Fleetbase\FleetOps\Support\Telematics\Afaqy;

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
        return \Fleetbase\FleetOps\Support\Telematics\Telemetry\Sample::timestamp($value);
    }
}
