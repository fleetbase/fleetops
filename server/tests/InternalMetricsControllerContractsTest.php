<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\MetricsController;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Stringable;

class FleetOpsMetricsRequestFake extends Request
{
    public function __construct(private array $values = [])
    {
        parent::__construct();
    }

    public function string($key = null, $default = null): Stringable
    {
        return str((string) ($this->values[$key] ?? $default ?? ''));
    }

    public function date($key = null, $format = null, $tz = null): ?Carbon
    {
        if (!array_key_exists($key, $this->values) || $this->values[$key] === null) {
            return null;
        }

        return Carbon::parse($this->values[$key], $tz);
    }
}

function fleetopsMetricsResolvePeriod(array $input): array
{
    $controller = new MetricsController();
    $method     = new ReflectionMethod(MetricsController::class, 'resolvePeriod');
    $method->setAccessible(true);

    return $method->invoke($controller, new FleetOpsMetricsRequestFake($input));
}

test('internal metrics controller resolves supported shorthand periods', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00'));

    try {
        [$start, $end] = fleetopsMetricsResolvePeriod(['period' => '14d']);

        expect($start->format('Y-m-d H:i:s'))->toBe('2026-07-12 12:00:00')
            ->and($end->format('Y-m-d H:i:s'))->toBe('2026-07-26 12:00:00');
    } finally {
        Carbon::setTestNow();
    }
});

test('internal metrics controller resolves explicit and default date windows', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00'));

    try {
        [$explicitStart, $explicitEnd] = fleetopsMetricsResolvePeriod([
            'start' => '2026-06-01 00:00:00',
            'end'   => '2026-06-30 23:59:59',
        ]);
        [$defaultStart, $defaultEnd] = fleetopsMetricsResolvePeriod(['period' => 'unsupported']);

        expect($explicitStart->format('Y-m-d H:i:s'))->toBe('2026-06-01 00:00:00')
            ->and($explicitEnd->format('Y-m-d H:i:s'))->toBe('2026-06-30 23:59:59')
            ->and($defaultStart->format('Y-m-d H:i:s'))->toBe('2026-06-26 12:00:00')
            ->and($defaultEnd->format('Y-m-d H:i:s'))->toBe('2026-07-26 12:00:00');
    } finally {
        Carbon::setTestNow();
    }
});
