<?php

use Fleetbase\FleetOps\Exports\DeviceExport;
use Fleetbase\FleetOps\Exports\MaintenanceExport;
use Fleetbase\FleetOps\Exports\MaintenanceScheduleExport;
use Fleetbase\FleetOps\Exports\VehicleExport;
use Fleetbase\FleetOps\Exports\WorkOrderExport;

test('expanded export headings include important operational fields', function () {
    expect((new VehicleExport())->headings())
        ->toContain('Plate Number')
        ->toContain('VIN')
        ->toContain('Fuel Card Number')
        ->toContain('Status')
        ->toContain('Body Type')
        ->toContain('Usage Type')
        ->toContain('Transmission')
        ->toContain('Measurement System')
        ->toContain('Engine Number')
        ->toContain('Engine Size (L)')
        ->toContain('Payload Capacity (kg)')
        ->toContain('Payload Volume (m3)')
        ->toContain('DPF Equipped')
        ->toContain('Insurance Value')
        ->toContain('Loan Amount')
        ->toContain('Vehicle Skills')
        ->toContain('Updated At');

    expect((new WorkOrderExport())->headings())
        ->toContain('Assignee')
        ->toContain('Target')
        ->toContain('Due At')
        ->toContain('Completion Percentage');

    expect((new DeviceExport())->headings())
        ->toContain('Connection Status')
        ->toContain('Attached To')
        ->toContain('Telematic Provider')
        ->toContain('Last Seen');
});

test('relationship exports use readable name columns', function () {
    expect((new VehicleExport())->headings())
        ->toContain('Driver')
        ->toContain('Vendor');

    expect((new WorkOrderExport())->headings())
        ->toContain('Assignee')
        ->toContain('Target');

    expect((new MaintenanceExport())->headings())
        ->toContain('Asset')
        ->toContain('Performed By');

    expect((new MaintenanceScheduleExport())->headings())
        ->toContain('Subject')
        ->toContain('Default Assignee');
});

test('vehicle export helpers format spreadsheet friendly values', function () {
    $export = new VehicleExport();
    $helper = fn (string $method, ...$arguments) => (new ReflectionMethod($export, $method))->invoke($export, ...$arguments);

    expect($helper('yesNo', true))->toBe('Yes')
        ->and($helper('yesNo', false))->toBe('No')
        ->and($helper('yesNo', null))->toBeNull()
        ->and($helper('joinSkills', ['hazmat', 'refrigerated']))->toBe('hazmat, refrigerated')
        ->and($helper('joinSkills', []))->toBeNull();
});

test('resources with visible export actions have backend export routes', function () {
    $routes = file_get_contents(dirname(__DIR__) . '/src/routes.php');

    foreach (['devices', 'sensors', 'telematics', 'maintenance-schedules', 'work-orders', 'maintenances', 'equipment', 'parts'] as $resource) {
        expect($routes)->toContain("\$router->fleetbaseRoutes('{$resource}'");
    }

    expect(substr_count($routes, "\$router->match(['get', 'post'], 'export', \$controller('export'));"))->toBeGreaterThanOrEqual(19);
});
