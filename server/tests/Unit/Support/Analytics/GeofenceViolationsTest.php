<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

use Fleetbase\FleetOps\Support\Analytics\GeofenceViolations;
use Fleetbase\Models\Company;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

function fleetopsGeofenceViolationsUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table geofence_events_log (uuid varchar(64), company_uuid varchar(64), driver_uuid varchar(64) null, vehicle_uuid varchar(64) null, order_uuid varchar(64) null, subject_uuid varchar(64) null, subject_type varchar(255) null, subject_name varchar(255) null, geofence_uuid varchar(64), geofence_type varchar(64), geofence_name varchar(255) null, event_type varchar(64), latitude numeric null, longitude numeric null, speed_kmh numeric null, dwell_duration_minutes integer null, occurred_at datetime null)');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    return $connection;
}

function fleetopsGeofenceViolationsCompany(): Company
{
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-geofence-violations'], true);

    return $company;
}

test('geofence violations summarizes today period dwell outliers and zone totals', function () {
    Carbon::setTestNow('2026-07-26 10:00:00');

    $connection = fleetopsGeofenceViolationsUseInMemoryConnection();
    $connection->table('geofence_events_log')->insert([
        [
            'uuid'                   => 'today-dwell-high',
            'company_uuid'           => 'company-geofence-violations',
            'driver_uuid'            => 'driver-1',
            'subject_name'           => 'Driver One',
            'geofence_uuid'          => 'zone-1',
            'geofence_type'          => 'zone',
            'geofence_name'          => 'Warehouse',
            'event_type'             => 'dwelled',
            'dwell_duration_minutes' => 47,
            'occurred_at'            => '2026-07-26 08:30:00',
        ],
        [
            'uuid'                   => 'today-dwell-low',
            'company_uuid'           => 'company-geofence-violations',
            'driver_uuid'            => 'driver-2',
            'subject_name'           => 'Driver Two',
            'geofence_uuid'          => 'zone-1',
            'geofence_type'          => 'zone',
            'geofence_name'          => 'Warehouse',
            'event_type'             => 'dwelled',
            'dwell_duration_minutes' => 12,
            'occurred_at'            => '2026-07-26 09:00:00',
        ],
        [
            'uuid'                   => 'period-unnamed',
            'company_uuid'           => 'company-geofence-violations',
            'driver_uuid'            => 'driver-3',
            'subject_name'           => 'Driver Three',
            'geofence_uuid'          => 'zone-2',
            'geofence_type'          => 'zone',
            'geofence_name'          => null,
            'event_type'             => 'entered',
            'dwell_duration_minutes' => null,
            'occurred_at'            => '2026-07-25 09:00:00',
        ],
        [
            'uuid'                   => 'outside-company',
            'company_uuid'           => 'company-other',
            'driver_uuid'            => 'driver-4',
            'subject_name'           => 'Driver Four',
            'geofence_uuid'          => 'zone-3',
            'geofence_type'          => 'zone',
            'geofence_name'          => 'Other Zone',
            'event_type'             => 'dwelled',
            'dwell_duration_minutes' => 99,
            'occurred_at'            => '2026-07-26 09:30:00',
        ],
    ]);

    $summary = GeofenceViolations::forCompany(fleetopsGeofenceViolationsCompany())
        ->between(Carbon::parse('2026-07-24'), Carbon::parse('2026-07-27'))
        ->get();

    expect($summary['violations_today'])->toBe(2)
        ->and($summary['violations_period'])->toBe(2)
        ->and($summary['top_dwells'])->toHaveCount(2)
        ->and($summary['top_dwells'][0])->toMatchArray([
            'driver_uuid'      => 'driver-1',
            'driver_name'      => 'Driver One',
            'zone_name'        => 'Warehouse',
            'duration_minutes' => 47,
        ])
        ->and($summary['by_zone']['labels'])->toBe(['Warehouse', 'Unnamed'])
        ->and($summary['by_zone']['data'])->toBe([2, 1]);

    Carbon::setTestNow();
});
