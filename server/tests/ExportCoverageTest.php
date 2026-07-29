<?php

use Fleetbase\FleetOps\Exports\ContactExport;
use Fleetbase\FleetOps\Exports\DeviceExport;
use Fleetbase\FleetOps\Exports\DriverExport;
use Fleetbase\FleetOps\Exports\EquipmentExport;
use Fleetbase\FleetOps\Exports\FleetExport;
use Fleetbase\FleetOps\Exports\FuelReportExport;
use Fleetbase\FleetOps\Exports\IssueExport;
use Fleetbase\FleetOps\Exports\MaintenanceExport;
use Fleetbase\FleetOps\Exports\MaintenanceScheduleExport;
use Fleetbase\FleetOps\Exports\OrderExport;
use Fleetbase\FleetOps\Exports\PartExport;
use Fleetbase\FleetOps\Exports\PlaceExport;
use Fleetbase\FleetOps\Exports\SensorExport;
use Fleetbase\FleetOps\Exports\ServiceAreaExport;
use Fleetbase\FleetOps\Exports\ServiceRateExport;
use Fleetbase\FleetOps\Exports\TelematicExport;
use Fleetbase\FleetOps\Exports\VehicleExport;
use Fleetbase\FleetOps\Exports\VendorExport;
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

test('all export classes expose stable spreadsheet headings and column formats', function () {
    $exports = [
        ContactExport::class,
        DeviceExport::class,
        DriverExport::class,
        EquipmentExport::class,
        FleetExport::class,
        FuelReportExport::class,
        IssueExport::class,
        MaintenanceExport::class,
        MaintenanceScheduleExport::class,
        OrderExport::class,
        PartExport::class,
        PlaceExport::class,
        SensorExport::class,
        ServiceAreaExport::class,
        ServiceRateExport::class,
        TelematicExport::class,
        VehicleExport::class,
        VendorExport::class,
        WorkOrderExport::class,
    ];

    foreach ($exports as $exportClass) {
        $export  = new $exportClass(['selected-resource']);
        $heading = $export->headings();
        $formats = $export->columnFormats();

        expect($heading)
            ->not->toBeEmpty()
            ->each->toBeString()
            ->and($heading)->toContain('ID')
            ->and($formats)->toBeArray();

        foreach (array_keys($formats) as $column) {
            expect($column)->toMatch('/^[A-Z]+$/');
        }
    }
});

test('vehicle export collection scopes selections and null location parts', function () {
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
    $schema = $connection->getSchemaBuilder();
    foreach (['vehicles' => ['uuid', 'public_id', 'company_uuid', 'driver_uuid', 'vendor_uuid', 'name', 'make', 'model', 'year', 'plate_number', 'status', '_key'], 'drivers' => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'current_job_uuid', '_key'], 'vendors' => ['uuid', 'public_id', 'company_uuid', 'name', '_key'], 'users' => ['uuid', 'public_id', 'company_uuid', 'name', '_key'], 'orders' => ['uuid', 'public_id', 'company_uuid', 'status', '_key']] as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }
    session(['company' => 'company-exp-1']);
    $connection->table('vehicles')->insert([
        ['uuid' => '77777777-7777-4777-8777-777777777801', 'company_uuid' => 'company-exp-1', 'name' => 'Selected Truck'],
        ['uuid' => '77777777-7777-4777-8777-777777777802', 'company_uuid' => 'company-exp-1', 'name' => 'Unselected Truck'],
    ]);

    $export = new VehicleExport(['77777777-7777-4777-8777-777777777801']);
    $rows   = $export->collection();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->name)->toBe('Selected Truck');

    // Location parts guard unresolvable points
    $reflection = new ReflectionMethod(VehicleExport::class, 'locationPart');
    $reflection->setAccessible(true);
    $nullish = $reflection->invoke($export, 'unparseable-location', 'lat');
    expect($nullish === null || $nullish === 0.0)->toBeTrue()
        ->and($reflection->invoke($export, new Fleetbase\LaravelMysqlSpatial\Types\Point(1.31, 103.81), 'lat'))->toBe(1.31);
});

test('fuel report export scopes selections against the session company', function () {
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
    $schema = $connection->getSchemaBuilder();
    $schema->create('fuel_reports', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'reported_by_uuid', 'driver_uuid', 'vehicle_uuid', 'report', 'amount', 'currency', 'volume', 'metric_unit', 'status', 'location', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });
    session(['company' => 'company-fexp-1']);
    $connection->table('fuel_reports')->insert([
        ['uuid' => '88888888-8888-4888-8888-888888888901', 'company_uuid' => 'company-fexp-1', 'report' => 'Selected'],
        ['uuid' => '88888888-8888-4888-8888-888888888902', 'company_uuid' => 'company-fexp-1', 'report' => 'Everything'],
    ]);

    $selected = new FuelReportExport(['88888888-8888-4888-8888-888888888901']);
    expect($selected->collection())->toHaveCount(1);

    $all = new FuelReportExport([]);
    expect($all->collection())->toHaveCount(2);
});
