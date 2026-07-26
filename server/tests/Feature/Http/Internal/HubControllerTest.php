<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\HubController;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

function fleetopsHubControllerFeatureUseInMemoryRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

class FleetOpsHubControllerFeatureProbe extends HubController
{
    public array $countCalls = [];

    public function __construct(
        public array $counts,
        public ?string $companyUuid = 'company-hub',
    ) {
    }

    protected function count(Builder $query, ?string $companyUuid): int
    {
        $this->countCalls[] = $companyUuid;

        return array_shift($this->counts) ?? 0;
    }

    protected function companyUuid(Request $request): ?string
    {
        return $this->companyUuid;
    }
}

test('internal hub resources builds kpis sections docs and prioritized actions', function () {
    fleetopsHubControllerFeatureUseInMemoryRelationConnection();

    $controller = new FleetOpsHubControllerFeatureProbe([
        2, // drivers
        3, // vehicles
        1, // fleets
        4, // vendors
        5, // contacts
        6, // places
        7, // issues
        1, // drivers_without_vehicles
        2, // vehicles_without_drivers
        3, // vehicles_without_devices
        4, // unattached_devices
        5, // resource_issues
        1, // overdue_vehicle_schedules
        2, // upcoming_vehicle_schedules
        3, // open_resource_work_orders
        4, // overdue_resource_work_orders
        5, // low_stock_parts
        6, // unmatched_fuel_transactions
        7, // fuel_reports
        8, // fuel_transactions
    ]);

    $payload = $controller->resources(Request::create('/int/v1/fleet-ops/hubs/resources'))->getData(true);

    expect($payload['kpis'])->toHaveCount(4)
        ->and($payload['kpis'][0])->toMatchArray([
            'key'   => 'drivers',
            'value' => 2,
            'tone'  => 'blue',
        ])
        ->and($payload['kpis'][2])->toMatchArray([
            'key'   => 'issues',
            'value' => 7,
            'tone'  => 'rose',
        ])
        ->and($payload['kpis'][3])->toMatchArray([
            'key'   => 'fuel_records',
            'value' => 15,
            'route' => 'management.fuel-transactions',
        ])
        ->and(array_column($payload['actions'], 'key'))->toBe([
            'assign_vehicles_to_drivers',
            'assign_drivers_to_vehicles',
            'attach_devices_to_vehicles',
            'review_resource_issues',
            'prepare_vehicle_maintenance',
            'close_overdue_work_orders',
        ])
        ->and(array_column($payload['sections'], 'key'))->toBe([
            'people_assets',
            'network',
            'exceptions',
        ])
        ->and($payload['sections'][0]['links'][0])->toMatchArray([
            'label' => 'Drivers',
            'count' => 2,
        ])
        ->and(array_column($payload['docs'], 'label'))->toBe([
            'Drivers',
            'Vehicles',
            'Fleets',
            'Contacts',
            'Places',
            'Issues',
        ])
        ->and($controller->countCalls)->toHaveCount(20)
        ->and(array_unique($controller->countCalls))->toBe(['company-hub']);
});

test('internal hub maintenance builds kpis sections docs and service actions', function () {
    fleetopsHubControllerFeatureUseInMemoryRelationConnection();

    $controller = new FleetOpsHubControllerFeatureProbe([
        2, // overdue schedules
        3, // upcoming schedules
        4, // open work orders
        5, // overdue work orders
        6, // open maintenance
        7, // high priority maintenance
        8, // low stock parts
        9, // equipment
    ], 'company-maintenance');

    $payload = $controller->maintenance(Request::create('/int/v1/fleet-ops/hubs/maintenance'))->getData(true);

    expect($payload['kpis'])->toHaveCount(4)
        ->and($payload['kpis'][0])->toMatchArray([
            'key'   => 'overdue_schedules',
            'value' => 2,
            'tone'  => 'rose',
        ])
        ->and($payload['kpis'][2])->toMatchArray([
            'key'   => 'open_work_orders',
            'value' => 4,
            'tone'  => 'amber',
        ])
        ->and(array_column($payload['actions'], 'key'))->toBe([
            'overdue_schedules',
            'overdue_work_orders',
            'high_priority_maintenance',
            'upcoming_service',
            'low_stock_parts',
        ])
        ->and(array_column($payload['sections'], 'key'))->toBe([
            'planning',
            'records',
        ])
        ->and($payload['sections'][0]['links'][0])->toMatchArray([
            'label' => 'Schedules',
            'count' => 5,
        ])
        ->and(array_column($payload['docs'], 'label'))->toBe([
            'Schedules',
            'Work Orders',
            'Equipment',
            'Parts',
        ])
        ->and($controller->countCalls)->toHaveCount(8)
        ->and(array_unique($controller->countCalls))->toBe(['company-maintenance']);
});
