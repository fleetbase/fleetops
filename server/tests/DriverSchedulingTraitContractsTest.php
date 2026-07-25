<?php

use Carbon\Carbon;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\Traits\DriverSchedulingTrait;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Http\Request;

class FleetOpsDriverSchedulingTraitProbe
{
    use DriverSchedulingTrait {
        calculateHosFromSchedule as protected traitCalculateHosFromSchedule;
    }

    public Driver $driver;
    public FleetOpsDriverSchedulingQueryFake $scheduleItems;
    public FleetOpsDriverSchedulingQueryFake $availabilities;
    public mixed $activeSchedule       = null;
    public mixed $activeShift          = null;
    public array $hosHours             = [0.0, 0.0];
    public bool $useRealHosCalculation = false;

    public function __construct()
    {
        $this->driver         = new Driver();
        $this->scheduleItems  = new FleetOpsDriverSchedulingQueryFake();
        $this->availabilities = new FleetOpsDriverSchedulingQueryFake();
    }

    protected function resolveDriver(string $id): Driver
    {
        $this->driver->setAttribute('resolved_id', $id);

        return $this->driver;
    }

    protected function scheduleItemsForDriver(Driver $driver): FleetOpsDriverSchedulingQueryFake
    {
        return $this->scheduleItems;
    }

    protected function availabilitiesForDriver(Driver $driver): FleetOpsDriverSchedulingQueryFake
    {
        return $this->availabilities;
    }

    protected function activeScheduleForDriver(Driver $driver): mixed
    {
        return $this->activeSchedule;
    }

    protected function activeShiftForDriver(Driver $driver, DateTimeInterface $date): mixed
    {
        return $this->activeShift;
    }

    protected function hosDurationExpression(): string
    {
        return 'TIMESTAMPDIFF(MINUTE, start_at, LEAST(COALESCE(end_at, NOW()), NOW()))';
    }

    protected function calculateHosFromSchedule(Driver $driver): array
    {
        return $this->useRealHosCalculation ? $this->traitCalculateHosFromSchedule($driver) : $this->hosHours;
    }

    public function calculateHosForTest(Driver $driver): array
    {
        $this->useRealHosCalculation = true;

        return $this->calculateHosFromSchedule($driver);
    }
}

class FleetOpsDriverSchedulingQueryFake
{
    public array $calls      = [];
    public array $items      = [];
    public array $sumResults = [];

    public function where(...$arguments): self
    {
        $this->calls[] = ['where', $arguments];

        return $this;
    }

    public function orderBy(string $column): self
    {
        $this->calls[] = ['orderBy', $column];

        return $this;
    }

    public function get(): array
    {
        return $this->items;
    }

    public function sum(mixed $expression): int|float
    {
        $this->calls[] = ['sum', (string) $expression];

        return array_shift($this->sumResults) ?? 0;
    }
}

test('driver scheduling trait filters schedule items and availabilities by requested range', function () {
    $controller                        = new FleetOpsDriverSchedulingTraitProbe();
    $controller->scheduleItems->items  = [['uuid' => 'schedule-item-uuid']];
    $controller->availabilities->items = [['uuid' => 'availability-uuid']];

    $request = new Request([
        'start_at' => '2026-06-01 08:00:00',
        'end_at'   => '2026-06-01 18:00:00',
    ]);

    $scheduleItems  = $controller->scheduleItems('driver_public', $request);
    $availabilities = $controller->availabilities('driver_public', $request);

    expect($scheduleItems->getData(true))->toBe(['data' => [['uuid' => 'schedule-item-uuid']]])
        ->and($availabilities->getData(true))->toBe(['data' => [['uuid' => 'availability-uuid']]])
        ->and($controller->scheduleItems->calls)->toContain(['where', ['start_at', '>=', '2026-06-01 08:00:00']])
        ->and($controller->scheduleItems->calls)->toContain(['where', ['end_at', '<=', '2026-06-01 18:00:00']])
        ->and($controller->scheduleItems->calls)->toContain(['orderBy', 'start_at'])
        ->and($controller->availabilities->calls)->toContain(['where', ['start_at', '>=', '2026-06-01 08:00:00']])
        ->and($controller->availabilities->calls)->toContain(['where', ['end_at', '<=', '2026-06-01 18:00:00']])
        ->and($controller->availabilities->calls)->toContain(['orderBy', 'start_at']);
});

test('driver scheduling trait reports hos defaults custom limits and compliance state', function () {
    $controller           = new FleetOpsDriverSchedulingTraitProbe();
    $controller->hosHours = [9.5, 55.0];

    expect($controller->hosStatus('driver_uuid')->getData(true))->toMatchArray([
        'daily_hours'  => 9.5,
        'weekly_hours' => 55.0,
        'daily_limit'  => 11,
        'weekly_limit' => 70,
        'hos_source'   => 'schedule',
        'is_compliant' => true,
    ]);

    $controller->activeSchedule = (object) [
        'hos_daily_limit'  => 8,
        'hos_weekly_limit' => 40,
        'hos_source'       => 'manual',
    ];
    $controller->hosHours = [8.0, 41.0];

    expect($controller->hosStatus('driver_uuid')->getData(true))->toMatchArray([
        'daily_hours'  => 8.0,
        'weekly_hours' => 41.0,
        'daily_limit'  => 8,
        'weekly_limit' => 40,
        'hos_source'   => 'manual',
        'is_compliant' => false,
    ]);
});

test('driver scheduling trait calculates schedule hos windows from daily and weekly shift sums', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-03 12:30:00'));

    $controller                            = new FleetOpsDriverSchedulingTraitProbe();
    $controller->scheduleItems->sumResults = [375, 1020];

    expect($controller->calculateHosForTest(new Driver()))->toBe([6.3, 17.0])
        ->and($controller->scheduleItems->calls)->toContain(['where', ['status', '!=', 'cancelled']])
        ->and($controller->scheduleItems->calls[0][0])->toBe('where')
        ->and($controller->scheduleItems->calls[0][1][0])->toBe('start_at')
        ->and($controller->scheduleItems->calls[0][1][1])->toBe('>=')
        ->and($controller->scheduleItems->calls)->toContain(['sum', 'TIMESTAMPDIFF(MINUTE, start_at, LEAST(COALESCE(end_at, NOW()), NOW()))']);

    Carbon::setTestNow();
});

test('driver scheduling trait returns active shift payloads or null when none exists', function () {
    $controller = new FleetOpsDriverSchedulingTraitProbe();

    expect($controller->activeShift('driver_uuid')->getData(true))->toBe(['data' => null]);

    $controller->activeShift = ['uuid' => 'shift-uuid', 'status' => 'active'];

    expect($controller->activeShift('driver_uuid')->getData(true))->toBe([
        'data' => ['uuid' => 'shift-uuid', 'status' => 'active'],
    ]);
});
