<?php

use Fleetbase\FleetOps\Http\Filter\MaintenanceScheduleFilter;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Http\Filter\Filter;

class FleetOpsMaintenanceScheduleFilterQuery
{
    public array $wheres = [];

    public function where(...$arguments): self
    {
        $this->wheres[] = $arguments;

        return $this;
    }
}

function fleetopsMaintenanceScheduleFilter(FleetOpsMaintenanceScheduleFilterQuery $builder): MaintenanceScheduleFilter
{
    $filter = (new ReflectionClass(MaintenanceScheduleFilter::class))->newInstanceWithoutConstructor();

    foreach ([
        'builder' => $builder,
        'session' => new class {
            public function get(string $key): ?string
            {
                return $key === 'company' ? 'company-uuid' : null;
            }
        },
    ] as $property => $value) {
        $reflection = new ReflectionProperty(Filter::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($filter, $value);
    }

    return $filter;
}

test('maintenance schedule filter scopes companies and resolves subject aliases', function () {
    $builder = new FleetOpsMaintenanceScheduleFilterQuery();
    $filter  = fleetopsMaintenanceScheduleFilter($builder);

    $filter->queryForInternal();
    $filter->queryForPublic();
    $filter->subjectType('vehicle');
    $filter->subjectType('fleet-ops:vehicle');
    $filter->subjectType(Vehicle::class);
    $filter->subjectUuid('vehicle-uuid');

    expect($builder->wheres)->toBe([
        ['company_uuid', 'company-uuid'],
        ['company_uuid', 'company-uuid'],
        ['subject_type', Vehicle::class],
        ['subject_type', Vehicle::class],
        ['subject_type', Vehicle::class],
        ['subject_uuid', 'vehicle-uuid'],
    ]);
});

test('maintenance schedule filter ignores empty subject filters', function () {
    $builder = new FleetOpsMaintenanceScheduleFilterQuery();
    $filter  = fleetopsMaintenanceScheduleFilter($builder);

    $filter->subjectType(null);
    $filter->subjectUuid(null);

    expect($builder->wheres)->toBe([]);
});
