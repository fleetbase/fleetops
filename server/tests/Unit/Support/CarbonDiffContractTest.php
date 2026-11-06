<?php

use Fleetbase\FleetOps\Console\Commands\ReplayVehicleLocations;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\PositionController;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\FleetOps\Support\Radar\RadarAgenda;
use Fleetbase\FleetOps\Support\Radar\RadarRules;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Carbon;

/**
 * Carbon 3 changed the default of `diffIn*` to a signed float, where Carbon 2
 * gave an absolute integer. Every value below has a fractional part or runs
 * backwards in time, so these fail on Carbon 3 if a call loses its explicit
 * `absolute` argument or its `(int)` cast.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00.500000', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('overdue words stay positive whole units', function () {
    $now = Carbon::parse('2026-09-15 12:00:30', 'UTC');

    expect(RadarRules::overdueWords($now->copy()->subSeconds(90), $now))->toBe('1m overdue')
        ->and(RadarRules::overdueWords($now->copy()->subMinutes(150), $now))->toBe('2h overdue')
        ->and(RadarRules::overdueWords($now->copy()->subHours(60), $now))->toBe('2d overdue');
});

test('due words count whole days forward', function () {
    $now = Carbon::parse('2026-09-15 12:00:00', 'UTC');

    expect(RadarRules::dueInWords($now->copy()->addDays(3)->addHours(6), $now))->toBe('in 3 days');
});

test('ago words clamp a future time and keep whole minutes', function () {
    $now = Carbon::parse('2026-09-15 12:00:00', 'UTC');

    expect(RadarRules::agoWords($now->copy()->addMinutes(5), $now))->toBe('1m ago')
        ->and(RadarRules::agoWords($now->copy()->subSeconds(150), $now))->toBe('2m ago');
});

test('agenda percentages use whole minutes and whole hours', function () {
    $start = Carbon::parse('2026-09-15 08:00:00', 'UTC');
    $pct   = new ReflectionMethod(RadarAgenda::class, 'pct');

    expect($pct->invoke(null, $start->copy()->addSeconds(5430), $start, 10))->toBe(15.0)
        ->and($pct->invoke(null, $start->copy()->subHour(), $start, 10))->toBe(0.0);
});

test('replay delay is a whole absolute number of seconds', function () {
    $command = (new ReflectionClass(ReplayVehicleLocations::class))->newInstanceWithoutConstructor();
    $delay   = new ReflectionMethod(ReplayVehicleLocations::class, 'calculateReplayDelay');

    expect($delay->invoke($command, '2026-01-01T10:00:00Z', '2026-01-01T10:00:08.900Z', 2.0))->toBe([8, 4.0])
        ->and($delay->invoke($command, '2026-01-01T10:00:08.900Z', '2026-01-01T10:00:00Z', 2.0))->toBe([8, 4.0]);
});

test('position metrics keep dwell, acceleration and duration positive and whole', function () {
    $position = fn (string $uuid, float $speed, string $at) => (object) [
        'uuid'        => $uuid,
        'speed'       => $speed,
        'coordinates' => new Point(1.30, 103.80),
        'created_at'  => Carbon::parse($at, 'UTC'),
    ];
    $positions = collect([
        $position('a', 0.0, '2026-07-28 08:00:00'),
        $position('b', 0.0, '2026-07-28 08:05:00.700000'),
        $position('c', 10.0, '2026-07-28 08:05:02.900000'),
    ]);

    $metrics = (new ReflectionMethod(PositionController::class, 'calculateMetrics'))
        ->invoke(new PositionController(), $positions);

    expect($metrics['total_duration'])->toBe(302)
        ->and($metrics['dwell_count'])->toBe(1)
        ->and($metrics['dwell_times'][0]['duration'])->toBe(300)
        ->and($metrics['acceleration_count'])->toBe(1)
        ->and($metrics['acceleration_events'][0]['acceleration'])->toBe(5.0);
});

test('equipment age, depreciation and replacement cost use whole years', function () {
    $equipment = new Equipment();
    $equipment->setRawAttributes([
        'purchased_at'   => Carbon::parse('2023-03-15 00:00:00', 'UTC'),
        'purchase_price' => 1000,
        'meta'           => ['depreciation_rate' => 0.1, 'inflation_rate' => 0.1],
    ], true);

    expect($equipment->age_in_days)->toBe(1280)
        ->and($equipment->depreciated_value)->toEqualWithDelta(700.0, 0.0001)
        ->and($equipment->getReplacementCostEstimate())->toEqualWithDelta(1331.0, 0.0001);
});

test('days until due stay signed whole days', function () {
    $maintenance = new Maintenance();
    $maintenance->setRawAttributes(['scheduled_at' => now()->addHours(36), 'status' => 'scheduled'], true);
    $overdue = new WorkOrder();
    $overdue->setRawAttributes(['due_at' => now()->subHours(36), 'status' => 'open'], true);

    expect($maintenance->days_until_due)->toBe(1)
        ->and($overdue->days_until_due)->toBe(-1);
});

test('repair durations stay whole hours', function () {
    $maintenance = new Maintenance();
    $maintenance->setRawAttributes(['started_at' => now()->subMinutes(150), 'completed_at' => now()], true);
    $workOrder = new WorkOrder();
    $workOrder->setRawAttributes(['opened_at' => now()->subMinutes(150), 'closed_at' => now()], true);

    expect($maintenance->duration_hours)->toBe(2.0)
        ->and($workOrder->getActualDuration())->toBe(2.0);
});
