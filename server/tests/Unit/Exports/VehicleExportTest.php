<?php

use Fleetbase\FleetOps\Exports\VehicleExport;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class FleetOpsVehicleExportProbe extends VehicleExport
{
    public function assignedOrdersCountForTest(Vehicle $vehicle): int
    {
        return $this->assignedOrdersCount($vehicle);
    }

    public function currentOrderReferenceForTest(Vehicle $vehicle): ?string
    {
        return $this->currentOrderReference($vehicle);
    }

    public function locationPartForTest($location, string $part)
    {
        return $this->locationPart($location, $part);
    }

    public function moneyForTest($amount, ?string $currency = 'USD'): ?string
    {
        return $this->money($amount, $currency);
    }
}

class FleetOpsVehicleExportDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }
}

function fleetopsVehicleExportUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table orders (uuid varchar(64), public_id varchar(64), tracking varchar(64), vehicle_assigned_uuid varchar(64), status varchar(64), created_at datetime null, deleted_at datetime null)');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    $app = function_exists('app') ? app() : Container::getInstance();
    if (!$app) {
        $app = new Container();
        Container::setInstance($app);
    }

    Facade::setFacadeApplication($app);
    $app->instance('db', new FleetOpsVehicleExportDatabaseProbe($connection));

    return $connection;
}

function fleetopsVehicleExportVehicle(array $attributes = []): Vehicle
{
    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(array_merge([
        'uuid'                                 => 'vehicle-export-1',
        'public_id'                            => 'vehicle_public_1',
        'internal_id'                          => 'veh-internal',
        'name'                                 => 'Reefer Truck 12',
        'description'                          => 'Primary cold-chain truck',
        'plate_number'                         => 'S1234',
        'vin'                                  => '1FTFW1ET0EKE12345',
        'make'                                 => 'Ford',
        'model'                                => 'F-150',
        'year'                                 => 2026,
        'trim'                                 => 'XL',
        'color'                                => 'white',
        'serial_number'                        => 'serial-12',
        'fuel_card_number'                     => 'fuel-card-77',
        'class'                                => 'light-duty',
        'type'                                 => 'truck',
        'status'                               => 'available',
        'online'                               => true,
        'call_sign'                            => 'COLD-12',
        'location'                             => ['type' => 'Point', 'coordinates' => [103.851959, 1.29027]],
        'heading'                              => 135,
        'altitude'                             => 30,
        'speed'                                => 42,
        'measurement_system'                   => 'metric',
        'fuel_volume_unit'                     => 'liter',
        'odometer'                             => 50200,
        'odometer_unit'                        => 'km',
        'odometer_at_purchase'                 => 1200,
        'body_type'                            => 'box',
        'body_sub_type'                        => 'refrigerated',
        'usage_type'                           => 'delivery',
        'ownership_type'                       => 'owned',
        'fuel_type'                            => 'diesel',
        'transmission'                         => 'automatic',
        'engine_number'                        => 'engine-12',
        'engine_make'                          => 'Cummins',
        'engine_model'                         => 'X12',
        'engine_family'                        => 'X',
        'engine_configuration'                 => 'inline',
        'cylinder_arrangement'                 => 'I6',
        'number_of_cylinders'                  => 6,
        'engine_size'                          => 6.7,
        'engine_displacement'                  => 6700,
        'horsepower'                           => 300,
        'horsepower_rpm'                       => 2500,
        'torque'                               => 900,
        'torque_rpm'                           => 1600,
        'fuel_capacity'                        => 120,
        'payload_capacity'                     => 1500,
        'towing_capacity'                      => 3000,
        'seating_capacity'                     => 2,
        'weight'                               => 4200,
        'length'                               => 620,
        'width'                                => 220,
        'height'                               => 260,
        'cargo_volume'                         => 35,
        'payload_capacity_volume'              => 18.5,
        'payload_capacity_pallets'             => 6,
        'payload_capacity_parcels'             => 80,
        'passenger_volume'                     => 3,
        'interior_volume'                      => 4,
        'ground_clearance'                     => 22,
        'bed_length'                           => 240,
        'emission_standard'                    => 'Euro 6',
        'dpf_equipped'                         => true,
        'scr_equipped'                         => false,
        'gvwr'                                 => 6500,
        'gcwr'                                 => 9000,
        'currency'                             => 'USD',
        'acquisition_cost'                     => 1250000,
        'current_value'                        => 990000,
        'insurance_value'                      => 1100000,
        'depreciation_rate'                    => 12,
        'estimated_service_life_distance'      => 300000,
        'estimated_service_life_distance_unit' => 'km',
        'estimated_service_life_months'        => 72,
        'purchased_at'                         => '2026-01-01 00:00:00',
        'lease_expires_at'                     => '2028-01-01 00:00:00',
        'financing_status'                     => 'financed',
        'loan_amount'                          => 8000,
        'loan_number_of_payments'              => 36,
        'loan_first_payment'                   => '2026-02-01',
        'skills'                               => ['cold-chain', null, 'hazmat'],
        'time_window_start'                    => '08:00',
        'time_window_end'                      => '18:00',
        'max_tasks'                            => 10,
        'return_to_depot'                      => true,
        'notes'                                => 'Use for high-priority chilled cargo.',
        'created_at'                           => '2026-01-10 12:00:00',
        'updated_at'                           => '2026-07-26 10:00:00',
    ], $attributes), true);

    $vehicle->setRelation('driver', (object) [
        'name'         => 'Ada Driver',
        'currentOrder' => (object) ['tracking' => 'TRK-RELATION'],
    ]);
    $vehicle->setRelation('vendor', (object) ['name' => 'Fleet Vendor']);

    return $vehicle;
}

test('vehicle export maps complete vehicle rows into spreadsheet headings', function () {
    $connection = fleetopsVehicleExportUseInMemoryConnection();
    $connection->table('orders')->insert([
        [
            'uuid'                  => 'assigned-order-1',
            'public_id'             => 'order_public_1',
            'tracking'              => 'TRK-1',
            'vehicle_assigned_uuid' => 'vehicle-export-1',
            'status'                => 'dispatched',
            'created_at'            => '2026-07-26 08:00:00',
            'deleted_at'            => null,
        ],
        [
            'uuid'                  => 'assigned-order-2',
            'public_id'             => 'order_public_2',
            'tracking'              => 'TRK-2',
            'vehicle_assigned_uuid' => 'vehicle-export-1',
            'status'                => 'completed',
            'created_at'            => '2026-07-25 08:00:00',
            'deleted_at'            => null,
        ],
    ]);

    $export = new VehicleExport();
    $row    = array_combine($export->headings(), $export->map(fleetopsVehicleExportVehicle()));

    expect($row)->toHaveCount(count($export->headings()))
        ->and($row['ID'])->toBe('vehicle_public_1')
        ->and($row['Name'])->toBe('Reefer Truck 12')
        ->and($row['Driver'])->toBe('Ada Driver')
        ->and($row['Vendor'])->toBe('Fleet Vendor')
        ->and($row['Online'])->toBe('Yes')
        ->and($row['Latitude'])->toBe(1.29027)
        ->and($row['Longitude'])->toBe(103.851959)
        ->and($row['DPF Equipped'])->toBe('Yes')
        ->and($row['SCR Equipped'])->toBe('No')
        ->and($row['Acquisition Cost'])->toBe('$12,500.00')
        ->and($row['Current Value'])->toBe('$9,900.00')
        ->and($row['Insurance Value'])->toBe('$11,000.00')
        ->and($row['Loan Amount'])->toBe('$8,000.00')
        ->and($row['Vehicle Skills'])->toBe('cold-chain, hazmat')
        ->and($row['Return To Depot'])->toBe('Yes')
        ->and($row['Assigned Order Count'])->toBe(2)
        ->and($row['Current Order Reference'])->toBe('TRK-RELATION')
        ->and($row['Notes'])->toBe('Use for high-priority chilled cargo.');
});

test('vehicle export formats columns and helper fallbacks', function () {
    $connection = fleetopsVehicleExportUseInMemoryConnection();
    $connection->table('orders')->insert([
        [
            'uuid'                  => 'completed-order',
            'public_id'             => 'completed_public',
            'tracking'              => 'TRK-COMPLETE',
            'vehicle_assigned_uuid' => 'vehicle-export-2',
            'status'                => 'completed',
            'created_at'            => '2026-07-26 08:00:00',
            'deleted_at'            => null,
        ],
        [
            'uuid'                  => 'active-order',
            'public_id'             => 'active_public',
            'tracking'              => null,
            'vehicle_assigned_uuid' => 'vehicle-export-2',
            'status'                => 'started',
            'created_at'            => '2026-07-26 09:00:00',
            'deleted_at'            => null,
        ],
    ]);

    $vehicle = fleetopsVehicleExportVehicle([
        'uuid'             => 'vehicle-export-2',
        'name'             => '',
        'currency'         => '',
        'online'           => null,
        'location'         => null,
        'acquisition_cost' => '',
        'skills'           => 'oversized',
    ]);
    $vehicle->setRelation('driver', (object) ['name' => null, 'currentOrder' => null]);

    $export  = new FleetOpsVehicleExportProbe();
    $formats = $export->columnFormats();
    $row     = array_combine($export->headings(), $export->map($vehicle));

    expect($formats)->toMatchArray([
        'CA' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        'CB' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        'CF' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        'CO' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        'CP' => NumberFormat::FORMAT_DATE_DDMMYYYY,
    ])
        ->and($row['Name'])->toBe('2026 Ford F-150 XL')
        ->and($row['Online'])->toBeNull()
        ->and($row['Latitude'])->toBe(0.0)
        ->and($row['Longitude'])->toBe(0.0)
        ->and($row['Currency'])->toBe('')
        ->and($row['Acquisition Cost'])->toBeNull()
        ->and($row['Vehicle Skills'])->toBe('oversized')
        ->and($row['Current Order Reference'])->toBe('active_public')
        ->and($export->assignedOrdersCountForTest($vehicle))->toBe(2)
        ->and($export->currentOrderReferenceForTest($vehicle))->toBe('active_public')
        ->and($export->locationPartForTest((object) ['latitude' => 12.34, 'longitude' => 56.78], 'lat'))->toBe(12.34)
        ->and($export->locationPartForTest((object) ['latitude' => 12.34, 'longitude' => 56.78], 'lng'))->toBe(56.78)
        ->and($export->moneyForTest(12345, null))->toBe('$123.45');

    Carbon::setTestNow();
});
