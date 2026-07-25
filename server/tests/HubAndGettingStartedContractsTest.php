<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\HubController;
use Fleetbase\FleetOps\Support\GettingStarted;
use Fleetbase\Models\Company;
use Illuminate\Http\Request;

class FleetOpsHubControllerProbe extends HubController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(HubController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsGettingStartedProbe extends GettingStarted
{
    public function callHelper(string $method): array
    {
        $reflection = new ReflectionMethod(GettingStarted::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this);
    }
}

test('hub controller resource actions prioritize onboarding operational exceptions and healthy fallback', function () {
    $controller = new FleetOpsHubControllerProbe();
    $counts     = [
        'drivers'                       => 0,
        'vehicles'                      => 0,
        'drivers_without_vehicles'      => 2,
        'vehicles_without_drivers'      => 3,
        'vehicles_without_devices'      => 4,
        'unattached_devices'            => 5,
        'resource_issues'               => 0,
        'issues'                        => 1,
        'overdue_vehicle_schedules'     => 0,
        'upcoming_vehicle_schedules'    => 2,
        'overdue_resource_work_orders'  => 0,
        'open_resource_work_orders'     => 1,
        'low_stock_parts'               => 2,
        'unmatched_fuel_transactions'   => 3,
        'fleets'                        => 0,
        'places'                        => 0,
        'vendors'                       => 0,
        'contacts'                      => 0,
    ];

    $actions = $controller->callHelper('resourceActions', $counts, 0);

    expect(array_column($actions, 'key'))->toBe([
        'add_drivers',
        'add_vehicles',
        'attach_devices_to_vehicles',
        'review_issues',
        'prepare_vehicle_maintenance',
        'review_open_work_orders',
    ])
        ->and($actions[2]['query'])->toEqual((object) ['attachment_state' => 'unattached'])
        ->and($actions[3]['query'])->toEqual((object) ['status' => 'open']);

    $healthyCounts                                 = array_map(fn () => 1, $counts);
    $healthyCounts['drivers_without_vehicles']     = 0;
    $healthyCounts['vehicles_without_drivers']     = 0;
    $healthyCounts['vehicles_without_devices']     = 0;
    $healthyCounts['unattached_devices']           = 0;
    $healthyCounts['resource_issues']              = 0;
    $healthyCounts['issues']                       = 0;
    $healthyCounts['overdue_vehicle_schedules']    = 0;
    $healthyCounts['upcoming_vehicle_schedules']   = 0;
    $healthyCounts['overdue_resource_work_orders'] = 0;
    $healthyCounts['open_resource_work_orders']    = 0;
    $healthyCounts['low_stock_parts']              = 0;
    $healthyCounts['unmatched_fuel_transactions']  = 0;

    $ready = $controller->callHelper('resourceActions', $healthyCounts, 1);

    expect($ready)->toHaveCount(1)
        ->and($ready[0]['key'])->toBe('ready')
        ->and($ready[0]['tone'])->toBe('success')
        ->and($ready[0]['route'])->toBeNull();
});

test('hub controller maintenance actions summarize service priorities', function () {
    $controller = new FleetOpsHubControllerProbe();

    $actions = $controller->callHelper('maintenanceActions', 1, 2, 0, 3, 4, 5, 0);

    expect(array_column($actions, 'key'))->toBe([
        'overdue_schedules',
        'overdue_work_orders',
        'high_priority_maintenance',
        'upcoming_service',
        'no_open_work_orders',
    ])
        ->and($actions[0]['description'])->toContain('1 recurring service schedule is overdue')
        ->and($actions[1]['description'])->toContain('3 work orders are past due');
});

test('hub controller small helpers build dashboard payload fragments', function () {
    $controller = new FleetOpsHubControllerProbe();
    session(['company' => 'company-session']);

    $requestWithUser = new Request();
    $requestWithUser->setUserResolver(fn () => (object) ['company_uuid' => 'user-company']);

    expect($controller->callHelper('companyUuid', $requestWithUser))->toBe('company-session')
        ->and($controller->callHelper('kpi', 'drivers', 'Drivers', 3, 'Ready', 'blue', 'id-card', 'management.drivers'))->toBe([
            'key'     => 'drivers',
            'label'   => 'Drivers',
            'value'   => 3,
            'caption' => 'Ready',
            'tone'    => 'blue',
            'icon'    => 'id-card',
            'route'   => 'management.drivers',
        ])
        ->and($controller->callHelper('action', 'add', 'Add', 'Create records', 'info', 'plus', 'route.name', ['status' => 'open']))->toMatchArray([
            'key'         => 'add',
            'query'       => (object) ['status' => 'open'],
        ])
        ->and($controller->callHelper('link', 'Drivers', 'management.drivers', 'id-card', 4, 'Manage drivers'))->toBe([
            'label'       => 'Drivers',
            'route'       => 'management.drivers',
            'icon'        => 'id-card',
            'count'       => 4,
            'description' => 'Manage drivers',
        ])
        ->and($controller->callHelper('doc', 'Drivers', 'id-card', 'fleet-ops/drivers', 'Drivers guide'))->toBe([
            'label'       => 'Drivers',
            'icon'        => 'id-card',
            'slug'        => 'fleet-ops/drivers',
            'title'       => 'Drivers guide',
            'description' => '',
        ]);
});

test('getting started recommendations expose the onboarding cards', function () {
    $company = new Company(['uuid' => 'company-uuid']);
    $helper  = new FleetOpsGettingStartedProbe($company);

    $recommendations = $helper->callHelper('recommendations');

    expect($recommendations)->toHaveCount(4)
        ->and(array_column($recommendations, 'key'))->toBe([
            'route_optimization',
            'live_fleet',
            'service_rates',
            'customer_portal',
        ])
        ->and($recommendations[0])->toMatchArray([
            'title'  => 'Route Optimization',
            'accent' => 'blue',
        ]);
});
