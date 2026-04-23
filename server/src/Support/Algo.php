<?php

namespace Fleetbase\FleetOps\Support;

use Webit\Util\EvalMath\EvalMath;

class Algo
{
    protected const FUNCTION_PATTERN = '/\b(max|min|ceil|floor|round)\(([^()]*)\)/';

    /**
     * Execute an algorithm strig.
     *
     * @return int
     */
    public static function exec($algorithm, $variables = [], $round = false)
    {
        $m                  = new EvalMath();
        $m->suppress_errors = true;

        $variables = static::normalizeVariables($variables);

        foreach ($variables as $key => $value) {
            $algorithm = str_replace('{' . $key . '}', $value, $algorithm);
        }

        $algorithm = static::evaluateFunctions($algorithm);
        $result    = $m->evaluate($algorithm);

        if ($result === false || $result === null || !is_numeric($result)) {
            return null;
        }

        if ($round) {
            return round($result, 2); // precision 2 cuz most likely dealing with $
        }

        return $result;
    }

    public static function isComputable($algorithm): bool
    {
        $result = static::exec($algorithm, static::validationFixture(), true);

        return $result !== null && is_numeric($result);
    }

    public static function normalizeVariables($variables = []): array
    {
        $variables = collect($variables)->all();

        $distanceM = (float) ($variables['distance_m'] ?? $variables['distance'] ?? 0);
        $timeS     = (float) ($variables['time_s'] ?? $variables['time'] ?? 0);

        $normalized = [
            'distance_m' => $distanceM,
            'distance'   => $distanceM,
            'distance_km'=> $distanceM / 1000,
            'distance_mi'=> $distanceM / 1609.344,
            'time_s'     => $timeS,
            'time'       => $timeS,
            'time_min'   => $timeS / 60,
            'stops'      => (int) ($variables['stops'] ?? 0),
            'waypoints'  => (int) ($variables['waypoints'] ?? 0),
            'parcels'    => (int) ($variables['parcels'] ?? 0),
            'entities'   => (int) ($variables['entities'] ?? 0),
            'base_fee'   => (float) ($variables['base_fee'] ?? 0),
        ];

        return array_merge($normalized, $variables);
    }

    protected static function validationFixture(): array
    {
        return static::normalizeVariables([
            'distance_m' => 25000,
            'time_s'     => 5400,
            'stops'      => 4,
            'waypoints'  => 2,
            'parcels'    => 3,
            'entities'   => 5,
            'base_fee'   => 100,
        ]);
    }

    protected static function evaluateFunctions(string $algorithm): string
    {
        while (preg_match(static::FUNCTION_PATTERN, $algorithm)) {
            $algorithm = preg_replace_callback(static::FUNCTION_PATTERN, function ($matches) {
                $function           = $matches[1];
                $args               = array_map('trim', explode(',', $matches[2]));
                $m                  = new EvalMath();
                $m->suppress_errors = true;
                $numbers            = array_map(function ($arg) use ($m) {
                    $result = $m->evaluate($arg);

                    if ($result === false || $result === null || !is_numeric($result)) {
                        return (float) $arg;
                    }

                    return (float) $result;
                }, $args);

                return match ($function) {
                    'max'   => (string) max($numbers[0] ?? 0, $numbers[1] ?? 0),
                    'min'   => (string) min($numbers[0] ?? 0, $numbers[1] ?? 0),
                    'ceil'  => (string) ceil($numbers[0] ?? 0),
                    'floor' => (string) floor($numbers[0] ?? 0),
                    'round' => (string) round($numbers[0] ?? 0, (int) ($numbers[1] ?? 0)),
                    default => $matches[0],
                };
            }, $algorithm);
        }

        return $algorithm;
    }
}
