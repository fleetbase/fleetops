<?php

use Fleetbase\FleetOps\Support\Metrics\DriversOnlineMetric;
use Fleetbase\FleetOps\Support\Metrics\OpenIssuesMetric;
use Fleetbase\FleetOps\Support\Metrics\OrdersCanceledMetric;
use Fleetbase\FleetOps\Support\Metrics\OrdersCompletedMetric;
use Fleetbase\FleetOps\Support\Metrics\OrdersInProgressMetric;
use Fleetbase\FleetOps\Support\Metrics\ResolvedIssuesMetric;
use Fleetbase\FleetOps\Support\Metrics\TotalCustomersMetric;
use Fleetbase\FleetOps\Support\Metrics\TotalDriversMetric;
use Fleetbase\Models\Company;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

class FleetOpsCountMetricsDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }
}

function fleetopsCountMetricsUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table orders (uuid varchar(64), company_uuid varchar(64), status varchar(64), created_at datetime null, deleted_at datetime null)');
    $connection->statement('create table issues (uuid varchar(64), company_uuid varchar(64), status varchar(64), resolved_at datetime null, created_at datetime null, deleted_at datetime null)');
    $connection->statement('create table contacts (uuid varchar(64), company_uuid varchar(64), type varchar(64), created_at datetime null, deleted_at datetime null)');
    $connection->statement('create table drivers (uuid varchar(64), company_uuid varchar(64), user_uuid varchar(64), online integer, current_job_uuid varchar(64) null, created_at datetime null, deleted_at datetime null)');
    $connection->statement('create table users (uuid varchar(64), type varchar(64) null, deleted_at datetime null)');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsCountMetricsDatabaseProbe($connection));

    return $connection;
}

function fleetopsCountMetricsCompany(): Company
{
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-count-metrics'], true);

    return $company;
}

test('order count metrics filter company status and reporting period', function () {
    $connection = fleetopsCountMetricsUseInMemoryConnection();
    $connection->table('orders')->insert([
        [
            'uuid'         => 'completed-in-period',
            'company_uuid' => 'company-count-metrics',
            'status'       => 'completed',
            'created_at'   => '2026-07-10 10:00:00',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'completed-outside-period',
            'company_uuid' => 'company-count-metrics',
            'status'       => 'completed',
            'created_at'   => '2026-06-10 10:00:00',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'canceled-in-period',
            'company_uuid' => 'company-count-metrics',
            'status'       => 'canceled',
            'created_at'   => '2026-07-11 10:00:00',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'assigned-in-period',
            'company_uuid' => 'company-count-metrics',
            'status'       => 'assigned',
            'created_at'   => '2026-07-12 10:00:00',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'driver-enroute-in-period',
            'company_uuid' => 'company-count-metrics',
            'status'       => 'driver_enroute',
            'created_at'   => '2026-07-13 10:00:00',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'created-ignored',
            'company_uuid' => 'company-count-metrics',
            'status'       => 'created',
            'created_at'   => '2026-07-14 10:00:00',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'other-company',
            'company_uuid' => 'other-company',
            'status'       => 'completed',
            'created_at'   => '2026-07-10 10:00:00',
            'deleted_at'   => null,
        ],
    ]);

    $company = fleetopsCountMetricsCompany();
    $start   = new DateTimeImmutable('2026-07-01 00:00:00');
    $end     = new DateTimeImmutable('2026-07-31 23:59:59');

    expect(OrdersCompletedMetric::slug())->toBe('orders_completed')
        ->and(OrdersCompletedMetric::forCompany($company)->between($start, $end)->format())->toBe('count')
        ->and(OrdersCompletedMetric::forCompany($company)->between($start, $end)->value())->toBe(1)
        ->and(OrdersCanceledMetric::slug())->toBe('orders_canceled')
        ->and(OrdersCanceledMetric::forCompany($company)->between($start, $end)->value())->toBe(1)
        ->and(OrdersInProgressMetric::slug())->toBe('orders_in_progress')
        ->and(OrdersInProgressMetric::forCompany($company)->between($start, $end)->value())->toBe(2);
});

test('issue count metrics distinguish open and resolved issue periods', function () {
    $connection = fleetopsCountMetricsUseInMemoryConnection();
    $connection->table('issues')->insert([
        [
            'uuid'         => 'pending-in-period',
            'company_uuid' => 'company-count-metrics',
            'status'       => 'pending',
            'resolved_at'  => null,
            'created_at'   => '2026-07-10 10:00:00',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'pending-outside-period',
            'company_uuid' => 'company-count-metrics',
            'status'       => 'pending',
            'resolved_at'  => null,
            'created_at'   => '2026-06-10 10:00:00',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'resolved-in-period',
            'company_uuid' => 'company-count-metrics',
            'status'       => 'resolved',
            'resolved_at'  => '2026-07-12 10:00:00',
            'created_at'   => '2026-06-10 10:00:00',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'resolved-outside-period',
            'company_uuid' => 'company-count-metrics',
            'status'       => 'resolved',
            'resolved_at'  => '2026-06-12 10:00:00',
            'created_at'   => '2026-06-10 10:00:00',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'other-company',
            'company_uuid' => 'other-company',
            'status'       => 'pending',
            'resolved_at'  => null,
            'created_at'   => '2026-07-10 10:00:00',
            'deleted_at'   => null,
        ],
    ]);

    $company = fleetopsCountMetricsCompany();
    $start   = new DateTimeImmutable('2026-07-01 00:00:00');
    $end     = new DateTimeImmutable('2026-07-31 23:59:59');

    expect(OpenIssuesMetric::slug())->toBe('open_issues')
        ->and(OpenIssuesMetric::forCompany($company)->between($start, $end)->format())->toBe('count')
        ->and(OpenIssuesMetric::forCompany($company)->between($start, $end)->value())->toBe(1)
        ->and(ResolvedIssuesMetric::slug())->toBe('resolved_issues')
        ->and(ResolvedIssuesMetric::forCompany($company)->between($start, $end)->value())->toBe(1);
});

test('driver and customer count metrics use company membership and online state', function () {
    $connection = fleetopsCountMetricsUseInMemoryConnection();
    $connection->table('contacts')->insert([
        [
            'uuid'         => 'customer-one',
            'company_uuid' => 'company-count-metrics',
            'type'         => 'customer',
            'created_at'   => '2026-07-10 10:00:00',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'vendor-ignored',
            'company_uuid' => 'company-count-metrics',
            'type'         => 'vendor',
            'created_at'   => '2026-07-10 10:00:00',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'other-customer',
            'company_uuid' => 'other-company',
            'type'         => 'customer',
            'created_at'   => '2026-07-10 10:00:00',
            'deleted_at'   => null,
        ],
    ]);
    $connection->table('drivers')->insert([
        [
            'uuid'             => 'online-working-driver',
            'company_uuid'     => 'company-count-metrics',
            'user_uuid'        => 'user-online-working',
            'online'           => 1,
            'current_job_uuid' => 'job-uuid',
            'created_at'       => '2026-07-10 10:00:00',
            'deleted_at'       => null,
        ],
        [
            'uuid'             => 'online-idle-driver',
            'company_uuid'     => 'company-count-metrics',
            'user_uuid'        => 'user-online-idle',
            'online'           => 1,
            'current_job_uuid' => null,
            'created_at'       => '2026-07-10 10:00:00',
            'deleted_at'       => null,
        ],
        [
            'uuid'             => 'offline-working-driver',
            'company_uuid'     => 'company-count-metrics',
            'user_uuid'        => 'user-offline-working',
            'online'           => 0,
            'current_job_uuid' => 'job-uuid',
            'created_at'       => '2026-07-10 10:00:00',
            'deleted_at'       => null,
        ],
        [
            'uuid'             => 'other-driver',
            'company_uuid'     => 'other-company',
            'user_uuid'        => 'user-other',
            'online'           => 1,
            'current_job_uuid' => 'job-uuid',
            'created_at'       => '2026-07-10 10:00:00',
            'deleted_at'       => null,
        ],
    ]);
    $connection->table('users')->insert([
        ['uuid' => 'user-online-working', 'type' => 'driver', 'deleted_at' => null],
        ['uuid' => 'user-online-idle', 'type' => 'driver', 'deleted_at' => null],
        ['uuid' => 'user-offline-working', 'type' => 'driver', 'deleted_at' => null],
        ['uuid' => 'user-other', 'type' => 'driver', 'deleted_at' => null],
    ]);

    $company = fleetopsCountMetricsCompany();

    expect(TotalCustomersMetric::slug())->toBe('total_customers')
        ->and(TotalCustomersMetric::forCompany($company)->format())->toBe('count')
        ->and(TotalCustomersMetric::forCompany($company)->value())->toBe(1)
        ->and(TotalDriversMetric::slug())->toBe('total_drivers')
        ->and(TotalDriversMetric::forCompany($company)->value())->toBe(3)
        ->and(DriversOnlineMetric::slug())->toBe('drivers_online')
        ->and(DriversOnlineMetric::forCompany($company)->value())->toBe(1);
});
