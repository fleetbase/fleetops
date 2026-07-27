<?php

use Fleetbase\FleetOps\Http\Filter\DeviceFilter;
use Fleetbase\Http\Filter\Filter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

if (!function_exists('Fleetbase\FleetOps\Http\Filter\now')) {
    eval('namespace Fleetbase\FleetOps\Http\Filter; function now($tz = null) { return \Illuminate\Support\Carbon::now($tz); }');
}

class FleetOpsDeviceFilterUnitQuery
{
    public array $calls = [];

    public function where(...$arguments): self
    {
        if (isset($arguments[0]) && is_callable($arguments[0])) {
            $nested = new self();
            $arguments[0]($nested);
            $this->calls[] = ['whereNested', $nested->calls];

            return $this;
        }

        $this->calls[] = ['where', $arguments];

        return $this;
    }

    public function search(?string $query): self
    {
        $this->calls[] = ['search', $query];

        return $this;
    }

    public function whereIn(string $column, mixed $values): self
    {
        $this->calls[] = ['whereIn', $column, $values];

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->calls[] = ['whereNotNull', $column];

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->calls[] = ['whereNull', $column];

        return $this;
    }

    public function orWhere(...$arguments): self
    {
        $this->calls[] = ['orWhere', $arguments];

        return $this;
    }

    public function orWhereBetween(string $column, array $bounds): self
    {
        $this->calls[] = ['orWhereBetween', $column, $bounds];

        return $this;
    }

    public function orWhereNull(string $column): self
    {
        $this->calls[] = ['orWhereNull', $column];

        return $this;
    }

    public function whereBetween(string $column, array $bounds): self
    {
        $this->calls[] = ['whereBetween', $column, $bounds];

        return $this;
    }

    public function whereDate(string $column, string $date): self
    {
        $this->calls[] = ['whereDate', $column, $date];

        return $this;
    }
}

function fleetopsDeviceFilterUnitFilter(FleetOpsDeviceFilterUnitQuery $builder, ?Request $request = null): DeviceFilter
{
    $filter = (new ReflectionClass(DeviceFilter::class))->newInstanceWithoutConstructor();

    foreach ([
        'builder' => $builder,
        'session' => new class {
            public function get(string $key): ?string
            {
                return $key === 'company' ? 'company-uuid' : null;
            }
        },
        'request' => $request ?? new Request(),
    ] as $property => $value) {
        $reflection = new ReflectionProperty(Filter::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($filter, $value);
    }

    return $filter;
}

test('device filter records empty relation attachment online and date branches', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-27 12:00:00'));

    $builder = new FleetOpsDeviceFilterUnitQuery();
    $filter  = fleetopsDeviceFilterUnitFilter($builder);

    $filter->queryForInternal();
    $filter->queryForPublic();
    $filter->query('temperature probe');
    $filter->status([]);
    $filter->deviceId(null);
    $filter->type(null);
    $filter->serialNumber(null);
    $filter->telematic(null);
    $filter->telematicUuid(null);
    $filter->vehicle(null);
    $filter->connectionStatus('');
    $filter->connectionStatus(['online', 'never_connected']);
    $filter->attachmentState('attached');
    $filter->attachmentState('ignored');
    $filter->lastOnlineAt(['2026-07-01', '2026-07-15']);
    $filter->updatedAt('2026-07-20');

    expect($builder->calls)->toContain(['where', ['company_uuid', 'company-uuid']])
        ->and($builder->calls)->toContain(['search', 'temperature probe'])
        ->and($builder->calls)->toContain(['whereNotNull', 'attachable_uuid']);

    $connectionStatus = collect($builder->calls)->filter(fn ($call) => $call[0] === 'whereNested' && count($call[1]) > 0)->first();
    $lastOnlineRange  = collect($builder->calls)->first(fn ($call) => $call[0] === 'whereBetween' && $call[1] === 'last_online_at');
    $updatedAtDate    = collect($builder->calls)->first(fn ($call) => $call[0] === 'whereDate' && $call[1] === 'updated_at');

    expect($connectionStatus[1])->toHaveCount(2)
        ->and($connectionStatus[1][0][0])->toBe('orWhere')
        ->and($connectionStatus[1][0][1][0])->toBe('last_online_at')
        ->and($connectionStatus[1][0][1][1])->toBe('>=')
        ->and($connectionStatus[1][0][1][2]->toDateTimeString())->toBe('2026-07-27 11:50:00')
        ->and($connectionStatus[1][1])->toBe(['orWhereNull', 'last_online_at'])
        ->and($lastOnlineRange[2][0]->toDateString())->toBe('2026-07-01')
        ->and($lastOnlineRange[2][1]->toDateString())->toBe('2026-07-15')
        ->and($updatedAtDate[2])->toBe('2026-07-20 00:00:00');

    expect(collect($builder->calls)->where(0, 'whereIn'))->toHaveCount(0)
        ->and(collect($builder->calls)->where(0, 'whereNull'))->toHaveCount(0);

    Carbon::setTestNow();
});
