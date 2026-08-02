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
use Fleetbase\FleetOps\Exports\VendorExport;
use Fleetbase\FleetOps\Exports\WorkOrderExport;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class FleetOpsOrderExportRow
{
    public function __construct(public array $relations = [])
    {
        foreach ($relations as $key => $value) {
            $this->{$key} = $value;
        }
    }

    public function loadMissing(array $relations): self
    {
        $this->relations['loaded'] = $relations;

        return $this;
    }
}

class FleetOpsDriverExportRow
{
    public function __construct(public array $attributes = [])
    {
        foreach ($attributes as $key => $value) {
            $this->{$key} = $value;
        }
    }

    public function orders(): object
    {
        return new class($this->attributes['orders_count'] ?? 0) {
            public function __construct(private int $count)
            {
            }

            public function count(): int
            {
                return $this->count;
            }
        };
    }
}

function fleetopsExportRow(array $attributes): object
{
    return (object) $attributes;
}

function fleetopsExportMap(object $export, object $row): array
{
    return array_combine($export->headings(), $export->map($row));
}

test('order export maps loaded operational references into spreadsheet rows', function () {
    $order = new FleetOpsOrderExportRow([
        'public_id'            => 'order_public',
        'trackingNumber'       => fleetopsExportRow(['tracking_number' => 'TN-123']),
        'internal_id'          => 'order-internal',
        'payload'              => fleetopsExportRow([
            'public_id' => 'payload_public',
            'entities'  => [
                fleetopsExportRow(['sku' => 'SKU-1', 'public_id' => 'entity_1']),
                fleetopsExportRow(['sku' => null, 'public_id' => 'entity_2']),
            ],
            'waypoints' => [
                fleetopsExportRow(['address' => 'Pickup Street']),
                fleetopsExportRow(['address' => 'Dropoff Street']),
            ],
        ]),
        'driver_name'          => 'Ada Driver',
        'vehicle_name'         => 'Truck 42',
        'customer_name'        => 'Customer Co',
        'facilitator_name'     => 'Facilitator Co',
        'total_entities'       => 2,
        'transaction_amount'   => 155.25,
        'transaction_currency' => 'USD',
        'pickup_name'          => 'Warehouse',
        'dropoff_name'         => 'Storefront',
        'return_name'          => 'Depot',
        'scheduled_at'         => '2026-07-27 10:00:00',
        'type'                 => 'transport',
        'status'               => 'created',
        'created_by_name'      => 'Creator User',
        'updated_by_name'      => 'Updater User',
        'created_at'           => '2026-07-26 09:00:00',
        'updated_at'           => '2026-07-26 09:30:00',
    ]);

    $export = new OrderExport();
    $row    = array_combine($export->headings(), $export->map($order));

    expect($order->relations['loaded'])->toBe(['trackingNumber', 'payload', 'customer', 'facilitator', 'driverAssigned', 'vehicleAssigned'])
        ->and($row)->toHaveCount(count($export->headings()))
        ->and($row['ID'])->toBe('order_public')
        ->and($row['Tracking Number'])->toBe('TN-123')
        ->and($row['Payload ID'])->toBe('payload_public')
        ->and($row['SKU'])->toBe('SKU-1|entity_2')
        ->and($row['Waypoints'])->toBe('Pickup Street|Dropoff Street')
        ->and($row['Driver'])->toBe('Ada Driver')
        ->and($row['Vehicle'])->toBe('Truck 42')
        ->and($row['Date Scheduled'])->toBe('2026-07-27 10:00:00')
        ->and($export->columnFormats())->toBe([
            'Q' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'V' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'W' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ]);
});

test('service rate export maps fees distance and surcharge flags', function () {
    $export = new ServiceRateExport();
    $row    = array_combine($export->headings(), $export->map(fleetopsExportRow([
        'public_id'                     => 'rate_public',
        'service_name'                  => 'Same Day',
        'service_type'                  => 'last mile',
        'base_fee'                      => 12345,
        'currency'                      => 'USD',
        'rate_calculation_method'       => 'fixed',
        'service_area_name'             => 'Downtown',
        'zone_name'                     => 'Central',
        'per_meter_flat_rate_fee'       => 0.25,
        'per_meter_unit'                => 'm',
        'max_distance'                  => 5000,
        'max_distance_unit'             => 'm',
        'has_cod_fee'                   => true,
        'cod_calculation_method'        => 'flat',
        'cod_flat_fee'                  => 250,
        'cod_percent'                   => null,
        'has_peak_hours_fee'            => false,
        'peak_hours_calculation_method' => 'percentage',
        'peak_hours_flat_fee'           => null,
        'peak_hours_percent'            => 10,
        'peak_hours_start'              => '17:00',
        'peak_hours_end'                => '19:00',
        'duration_terms'                => 'same_day',
        'estimated_days'                => 1,
        'created_at'                    => '2026-07-26 09:00:00',
        'updated_at'                    => '2026-07-26 10:00:00',
    ])));

    expect($row['ID'])->toBe('rate_public')
        ->and($row['Type'])->toBe('Last Mile')
        ->and($row['Base Fee'])->toBe('$123.45')
        ->and($row['Has COD Fee'])->toBe('Yes')
        ->and($row['Has Peak Hours Fee'])->toBe('No')
        ->and($row['Max Distance'])->toBe(5000)
        ->and($export->columnFormats())->toBe([
            'Y' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'Z' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ]);
});

test('maintenance export maps lifecycle costs and overdue status', function () {
    $export = new MaintenanceExport();
    $row    = array_combine($export->headings(), $export->map(fleetopsExportRow([
        'public_id'          => 'maintenance_public',
        'summary'            => 'Oil change',
        'maintainable_name'  => 'Truck 42',
        'performed_by_name'  => 'Mechanic User',
        'work_order_subject' => 'WO-100',
        'type'               => 'preventive',
        'status'             => 'completed',
        'priority'           => 'high',
        'odometer'           => 50200,
        'engine_hours'       => 1200,
        'scheduled_at'       => '2026-07-20 08:00:00',
        'started_at'         => '2026-07-20 08:30:00',
        'completed_at'       => '2026-07-20 10:00:00',
        'labor_cost'         => 120,
        'parts_cost'         => 80,
        'tax'                => 12,
        'total_cost'         => 212,
        'currency'           => 'USD',
        'duration_hours'     => 1.5,
        'is_overdue'         => false,
        'days_until_due'     => 0,
        'created_at'         => '2026-07-19 09:00:00',
        'updated_at'         => '2026-07-20 10:00:00',
    ])));

    expect($row['ID'])->toBe('maintenance_public')
        ->and($row['Asset'])->toBe('Truck 42')
        ->and($row['Performed By'])->toBe('Mechanic User')
        ->and($row['Overdue'])->toBe('No')
        ->and($row['Total Cost'])->toBe(212)
        ->and($export->columnFormats())->toBe([
            'K' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'L' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'M' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'V' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'W' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ]);
});

test('device export maps attachment and online state', function () {
    $export = new DeviceExport();
    $row    = array_combine($export->headings(), $export->map(fleetopsExportRow([
        'public_id'         => 'device_public',
        'name'              => 'Tracker 77',
        'device_id'         => 'IMEI-77',
        'connection_status' => 'connected',
        'attached_to_name'  => 'Truck 42',
        'telematic_name'    => 'Provider Account',
        'sensors_count'     => 3,
        'last_online_at'    => '2026-07-26 10:00:00',
        'provider'          => 'samsara',
        'type'              => 'gps',
        'serial_number'     => 'serial-77',
        'imei'              => 'imei-77',
        'status'            => 'active',
        'attachable_uuid'   => 'vehicle_uuid',
        'online'            => true,
        'created_at'        => '2026-07-25 09:00:00',
        'updated_at'        => '2026-07-26 10:00:00',
    ])));

    expect($row['ID'])->toBe('device_public')
        ->and($row['Connection Status'])->toBe('connected')
        ->and($row['Attachment State'])->toBe('Attached')
        ->and($row['Online'])->toBe('Yes')
        ->and($row['Sensors Count'])->toBe(3)
        ->and($export->map(fleetopsExportRow([
            'public_id'         => 'device_public_2',
            'name'              => 'Tracker 88',
            'device_id'         => 'IMEI-88',
            'connection_status' => 'disconnected',
            'attached_to_name'  => null,
            'telematic_name'    => null,
            'sensors_count'     => 0,
            'last_online_at'    => null,
            'provider'          => 'manual',
            'type'              => 'gps',
            'serial_number'     => 'serial-88',
            'imei'              => 'imei-88',
            'status'            => 'inactive',
            'attachable_uuid'   => null,
            'online'            => false,
            'created_at'        => '2026-07-25 09:00:00',
            'updated_at'        => '2026-07-26 10:00:00',
        ]))[13])->toBe('Unattached')
        ->and($export->map(fleetopsExportRow([
            'public_id'         => 'device_public_2',
            'name'              => 'Tracker 88',
            'device_id'         => 'IMEI-88',
            'connection_status' => 'disconnected',
            'attached_to_name'  => null,
            'telematic_name'    => null,
            'sensors_count'     => 0,
            'last_online_at'    => null,
            'provider'          => 'manual',
            'type'              => 'gps',
            'serial_number'     => 'serial-88',
            'imei'              => 'imei-88',
            'status'            => 'inactive',
            'attachable_uuid'   => null,
            'online'            => false,
            'created_at'        => '2026-07-25 09:00:00',
            'updated_at'        => '2026-07-26 10:00:00',
        ]))[14])->toBe('No')
        ->and($export->columnFormats())->toBe([
            'H' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'P' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'Q' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ]);
});

test('contact vendor and fuel report exports map simple operational rows', function () {
    $contact = new ContactExport();
    $vendor  = new VendorExport();
    $fuel    = new FuelReportExport();

    expect(fleetopsExportMap($contact, fleetopsExportRow([
        'public_id'   => 'contact_public',
        'internal_id' => 'contact-internal',
        'name'        => 'Dispatch Contact',
        'title'       => 'Manager',
        'type'        => 'customer',
        'address'     => '1 Fleet Way',
        'email'       => 'dispatch@example.test',
        'phone'       => '15551234567',
        'created_at'  => '2026-07-25 09:00:00',
        'updated_at'  => '2026-07-26 09:00:00',
    ])))->toMatchArray([
        'ID'          => 'contact_public',
        'Internal ID' => 'contact-internal',
        'Phone'       => '15551234567',
    ])
        ->and($contact->columnFormats())->toBe([
            'H' => '+#',
            'I' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'J' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ])
        ->and(fleetopsExportMap($vendor, fleetopsExportRow([
            'public_id'   => 'vendor_public',
            'internal_id' => 'vendor-internal',
            'name'        => 'Vendor Co',
            'business_id' => 'BRN-1',
            'address'     => '2 Vendor Road',
            'email'       => 'ops@vendor.test',
            'website_url' => 'https://vendor.test',
            'phone'       => '15557654321',
            'type'        => 'carrier',
            'country'     => 'US',
            'status'      => 'active',
            'created_at'  => '2026-07-25 09:00:00',
            'updated_at'  => '2026-07-26 09:00:00',
        ])))->toMatchArray([
            'ID'          => 'vendor_public',
            'Business ID' => 'BRN-1',
            'Website URL' => 'https://vendor.test',
        ])
        ->and($vendor->columnFormats())->toBe([
            'H' => '+#',
            'L' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'M' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ])
        ->and(fleetopsExportMap($fuel, fleetopsExportRow([
            'public_id'    => 'fuel_public',
            'reporter'     => 'driver',
            'driver_name'  => 'Ada Driver',
            'vehicle_name' => 'Truck 42',
            'status'       => 'submitted',
            'amount'       => 88.5,
            'currency'     => 'USD',
            'volume'       => 40,
            'metric_unit'  => 'L',
            'odometer'     => 50200,
            'type'         => 'diesel',
            'source'       => 'manual',
            'provider'     => 'petro-app',
            'created_at'   => '2026-07-25 09:00:00',
            'updated_at'   => '2026-07-26 09:00:00',
        ])))->toMatchArray([
            'ID'       => 'fuel_public',
            'Reporter' => 'driver',
            'Provider' => 'petro-app',
        ])
        ->and($fuel->columnFormats())->toBe([
            'M' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'N' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ]);
});

test('asset inventory exports map assignment warranty and status fields', function () {
    $equipment = new EquipmentExport();
    $part      = new PartExport();
    $sensor    = new SensorExport();
    $telematic = new TelematicExport();

    expect(fleetopsExportMap($equipment, fleetopsExportRow([
        'public_id'          => 'equipment_public',
        'name'               => 'Lift Gate',
        'code'               => 'LG-1',
        'type'               => 'hydraulic',
        'status'             => 'active',
        'serial_number'      => 'serial-lg',
        'manufacturer'       => 'LiftCo',
        'model'              => 'L100',
        'equipped_to_name'   => 'Truck 42',
        'is_equipped'        => true,
        'warranty_name'      => 'Warranty A',
        'purchased_at'       => '2026-01-01',
        'purchase_price'     => 1200,
        'currency'           => 'USD',
        'age_in_days'        => 200,
        'depreciated_value'  => 900,
        'created_at'         => '2026-07-25 09:00:00',
        'updated_at'         => '2026-07-26 09:00:00',
    ])))->toMatchArray([
        'ID'          => 'equipment_public',
        'Equipped To' => 'Truck 42',
        'Is Equipped' => 'Yes',
    ])
        ->and(fleetopsExportMap($part, fleetopsExportRow([
            'public_id'        => 'part_public',
            'name'             => 'Brake Pad',
            'sku'              => 'BP-1',
            'type'             => 'brake',
            'status'           => 'active',
            'quantity_on_hand' => 4,
            'unit_cost'        => 25,
            'msrp'             => 40,
            'currency'         => 'USD',
            'manufacturer'     => 'PartsCo',
            'model'            => 'P100',
            'serial_number'    => 'serial-part',
            'barcode'          => 'barcode-1',
            'vendor_name'      => 'Vendor Co',
            'asset_name'       => 'Truck 42',
            'warranty_name'    => 'Warranty B',
            'total_value'      => 100,
            'is_in_stock'      => true,
            'is_low_stock'     => false,
            'created_at'       => '2026-07-25 09:00:00',
            'updated_at'       => '2026-07-26 09:00:00',
        ])))->toMatchArray([
            'ID'         => 'part_public',
            'Part Number'=> 'BP-1',
            'In Stock'   => 'Yes',
            'Low Stock'  => 'No',
        ])
        ->and(fleetopsExportMap($sensor, fleetopsExportRow([
            'public_id'        => 'sensor_public',
            'name'             => 'Temperature',
            'telematic'        => fleetopsExportRow(['name' => 'Provider Account']),
            'telematic_uuid'   => 'telematic_uuid',
            'device_name'      => 'Tracker 77',
            'type'             => 'temperature',
            'last_value'       => 2.5,
            'unit'             => 'C',
            'status'           => 'ok',
            'threshold_status' => 'normal',
            'min_threshold'    => -5,
            'max_threshold'    => 8,
            'serial_number'    => 'serial-sensor',
            'imei'             => 'imei-sensor',
            'last_reading_at'  => '2026-07-26 10:00:00',
            'attached_to_name' => 'Cold Box',
            'is_active'        => true,
            'created_at'       => '2026-07-25 09:00:00',
            'updated_at'       => '2026-07-26 09:00:00',
        ])))->toMatchArray([
            'ID'        => 'sensor_public',
            'Telematic' => 'Provider Account',
            'Active'    => 'Yes',
        ])
        ->and(fleetopsExportMap($telematic, fleetopsExportRow([
            'public_id'       => 'telematic_public',
            'name'            => 'Telematic Box',
            'provider'        => 'samsara',
            'status'          => 'active',
            'model'           => 'T100',
            'serial_number'   => 'serial-t',
            'imei'            => 'imei-t',
            'iccid'           => 'iccid-t',
            'imsi'            => 'imsi-t',
            'msisdn'          => 'msisdn-t',
            'last_seen_at'    => '2026-07-26 10:00:00',
            'warranty_name'   => 'Warranty C',
            'is_online'       => false,
            'signal_strength' => -75,
            'created_at'      => '2026-07-25 09:00:00',
            'updated_at'      => '2026-07-26 09:00:00',
        ])))->toMatchArray([
            'ID'       => 'telematic_public',
            'Online'   => 'No',
            'Warranty' => 'Warranty C',
        ]);
});

test('fleet place issue schedule service area driver and work order exports map nested fields', function () {
    $fleet       = new FleetExport();
    $place       = new PlaceExport();
    $issue       = new IssueExport();
    $schedule    = new MaintenanceScheduleExport();
    $serviceArea = new ServiceAreaExport();
    $driver      = new DriverExport();
    $workOrder   = new WorkOrderExport();

    expect(fleetopsExportMap($fleet, fleetopsExportRow([
        'public_id'             => 'fleet_public',
        'name'                  => 'North Fleet',
        'serviceArea'           => fleetopsExportRow(['name' => 'North Area']),
        'parentFleet'           => fleetopsExportRow(['name' => 'Parent Fleet']),
        'vendor'                => fleetopsExportRow(['name' => 'Vendor Co']),
        'zone'                  => fleetopsExportRow(['name' => 'Zone A']),
        'drivers_count'         => 5,
        'drivers_online_count'  => 4,
        'vehicles_count'        => 3,
        'vehicles_online_count' => 2,
        'task'                  => 'delivery',
        'status'                => 'active',
        'created_at'            => '2026-07-25 09:00:00',
        'updated_at'            => '2026-07-26 09:00:00',
    ])))->toMatchArray([
        'ID'                    => 'fleet_public',
        'Service Area'          => 'North Area',
        'Drivers Count'         => 5,
        'Vehicles Online Count' => 2,
    ])
        ->and(fleetopsExportMap($place, fleetopsExportRow([
            'public_id'            => 'place_public',
            'name'                 => 'Warehouse',
            'phone'                => '15551234567',
            'address'              => '1 fleet way',
            'street1'              => '1 Fleet Way',
            'street2'              => 'Dock 2',
            'city'                 => 'singapore',
            'province'             => 'central',
            'postal_code'          => '018956',
            'neighborhood'         => 'Downtown',
            'district'             => 'District 1',
            'building'             => 'Tower',
            'security_access_code' => '1234',
            'country_name'         => 'singapore',
            'owner'                => fleetopsExportRow(['name' => null, 'public_id' => 'owner_public']),
            'type'                 => 'warehouse',
            'created_at'           => '2026-07-25 09:00:00',
            'updated_at'           => '2026-07-26 09:00:00',
        ])))->toMatchArray([
            'ID'      => 'place_public',
            'Address' => '1 FLEET WAY',
            'City'    => 'SINGAPORE',
            'Owner'   => 'owner_public',
        ])
        ->and(fleetopsExportMap($issue, fleetopsExportRow([
            'public_id'     => 'issue_public',
            'issue_id'      => 'ISS-1',
            'title'         => 'Broken mirror',
            'report'        => 'Mirror damaged',
            'priority'      => 'high',
            'type'          => 'vehicle',
            'category'      => 'damage',
            'tags'          => ['safety', 'vehicle'],
            'reporter_name' => 'Reporter User',
            'reporter_id'   => 'reporter_public',
            'assignee_name' => 'Assignee User',
            'assignee_id'   => 'assignee_public',
            'driver_name'   => 'Ada Driver',
            'vehicle_name'  => 'Truck 42',
            'vehicle_id'    => 'vehicle_public',
            'order'         => fleetopsExportRow(['public_id' => null, 'tracking' => 'TRK-ISSUE']),
            'status'        => 'open',
            'resolved_at'   => null,
            'created_at'    => '2026-07-25 09:00:00',
            'updated_at'    => '2026-07-26 09:00:00',
        ])))->toMatchArray([
            'ID'           => 'issue_public',
            'Tags'         => 'safety, vehicle',
            'Linked Order' => 'TRK-ISSUE',
        ])
        ->and(fleetopsExportMap($schedule, fleetopsExportRow([
            'public_id'                 => 'schedule_public',
            'name'                      => 'Monthly service',
            'subject_name'              => 'Truck 42',
            'type'                      => 'vehicle',
            'status'                    => 'active',
            'interval_method'           => 'time',
            'interval_type'             => 'calendar',
            'interval_value'            => 1,
            'interval_unit'             => 'month',
            'interval_distance'         => 5000,
            'interval_engine_hours'     => 100,
            'last_service_odometer'     => 45000,
            'last_service_engine_hours' => 1000,
            'last_service_date'         => '2026-06-01',
            'next_due_date'             => '2026-07-01',
            'next_due_odometer'         => 50000,
            'next_due_engine_hours'     => 1100,
            'default_priority'          => 'medium',
            'default_assignee_name'     => 'Mechanic User',
            'last_triggered_at'         => '2026-06-01 08:00:00',
            'created_at'                => '2026-07-25 09:00:00',
            'updated_at'                => '2026-07-26 09:00:00',
        ])))->toMatchArray([
            'ID'               => 'schedule_public',
            'Subject'          => 'Truck 42',
            'Default Assignee' => 'Mechanic User',
        ])
        ->and(fleetopsExportMap($serviceArea, fleetopsExportRow([
            'public_id'               => 'area_public',
            'name'                    => 'North Area',
            'type'                    => 'polygon',
            'zones'                   => new Collection([
                fleetopsExportRow(['name' => 'Zone A']),
                fleetopsExportRow(['name' => 'Zone B']),
            ]),
            'country'                 => 'SG',
            'color'                   => '#00ff00',
            'stroke_color'            => '#008800',
            'trigger_on_entry'        => true,
            'trigger_on_exit'         => false,
            'dwell_threshold_minutes' => 15,
            'speed_limit_kmh'         => 60,
            'status'                  => 'active',
            'created_at'              => '2026-07-25 09:00:00',
            'updated_at'              => '2026-07-26 09:00:00',
        ])))->toMatchArray([
            'ID'               => 'area_public',
            'Zones'            => 'Zone A, Zone B',
            'Trigger On Entry' => 'Yes',
            'Trigger On Exit'  => 'No',
        ])
        ->and(fleetopsExportMap($driver, new FleetOpsDriverExportRow([
            'public_id'              => 'driver_public',
            'internal_id'            => 'driver-internal',
            'name'                   => 'Ada Driver',
            'email'                  => 'ada@example.test',
            'vendor_name'            => 'Vendor Co',
            'vehicle_name'           => 'Truck 42',
            'phone'                  => '15551234567',
            'drivers_license_number' => 'D123',
            'license_expiry'         => '2027-01-01',
            'country'                => 'SG',
            'city'                   => 'Singapore',
            'currency'               => 'SGD',
            'online'                 => true,
            'status'                 => 'active',
            'orders_count'           => 3,
            'currentOrder'           => fleetopsExportRow(['tracking' => null, 'public_id' => 'order_public']),
            'created_at'             => '2026-07-25 09:00:00',
            'updated_at'             => '2026-07-26 09:00:00',
        ])))->toMatchArray([
            'ID'                    => 'driver_public',
            'Online'                => 'Yes',
            'Assigned Orders Count' => 3,
            'Current Order'         => 'order_public',
        ])
        ->and(fleetopsExportMap($workOrder, fleetopsExportRow([
            'public_id'             => 'work_order_public',
            'code'                  => 'WO-1',
            'subject'               => 'Repair mirror',
            'category'              => 'repair',
            'status'                => 'open',
            'priority'              => 'high',
            'target_name'           => 'Truck 42',
            'assignee_name'         => 'Mechanic User',
            'opened_at'             => '2026-07-25 09:00:00',
            'due_at'                => '2026-07-27 09:00:00',
            'closed_at'             => null,
            'completion_percentage' => 50,
            'is_overdue'            => true,
            'days_until_due'        => -1,
            'created_at'            => '2026-07-25 09:00:00',
            'updated_at'            => '2026-07-26 09:00:00',
        ])))->toMatchArray([
            'ID'       => 'work_order_public',
            'Target'   => 'Truck 42',
            'Overdue'  => 'Yes',
        ]);
});
