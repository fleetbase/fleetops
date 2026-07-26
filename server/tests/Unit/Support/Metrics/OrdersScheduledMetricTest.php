<?php

use Fleetbase\FleetOps\Support\Metrics\OrdersScheduledMetric;
use Fleetbase\Models\Company;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

class FleetOpsOrdersScheduledMetricProbe extends OrdersScheduledMetric
{
    public function queryForTest(?DateTimeInterface $start, ?DateTimeInterface $end): Builder
    {
        return $this->query($start, $end);
    }

    public function aggregateForTest($query): int
    {
        return $this->aggregate($query);
    }
}

class FleetOpsOrdersScheduledMetricDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }
}

function fleetopsOrdersScheduledMetricUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table orders (uuid varchar(64), company_uuid varchar(64), status varchar(64), scheduled_at datetime null, created_at datetime null, deleted_at datetime null)');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsOrdersScheduledMetricDatabaseProbe($connection));

    return $connection;
}

function fleetopsOrdersScheduledMetricCompany(): Company
{
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-scheduled'], true);

    return $company;
}

test('orders scheduled metric counts future created orders for the company and period', function () {
    Carbon::setTestNow('2026-07-26 10:00:00');
    $connection = fleetopsOrdersScheduledMetricUseInMemoryConnection();
    $connection->table('orders')->insert([
        [
            'uuid'         => 'scheduled-in-period',
            'company_uuid' => 'company-scheduled',
            'status'       => 'created',
            'scheduled_at' => '2026-07-27 10:00:00',
            'created_at'   => '2026-07-20 12:00:00',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'scheduled-outside-period',
            'company_uuid' => 'company-scheduled',
            'status'       => 'created',
            'scheduled_at' => '2026-07-28 10:00:00',
            'created_at'   => '2026-06-20 12:00:00',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'past-scheduled',
            'company_uuid' => 'company-scheduled',
            'status'       => 'created',
            'scheduled_at' => '2026-07-25 10:00:00',
            'created_at'   => '2026-07-20 12:00:00',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'wrong-status',
            'company_uuid' => 'company-scheduled',
            'status'       => 'dispatched',
            'scheduled_at' => '2026-07-27 10:00:00',
            'created_at'   => '2026-07-20 12:00:00',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'wrong-company',
            'company_uuid' => 'other-company',
            'status'       => 'created',
            'scheduled_at' => '2026-07-27 10:00:00',
            'created_at'   => '2026-07-20 12:00:00',
            'deleted_at'   => null,
        ],
    ]);

    $metric = FleetOpsOrdersScheduledMetricProbe::forCompany(fleetopsOrdersScheduledMetricCompany());
    $query  = $metric->queryForTest(
        new DateTimeImmutable('2026-07-01 00:00:00'),
        new DateTimeImmutable('2026-07-31 23:59:59')
    );

    expect(OrdersScheduledMetric::slug())->toBe('orders_scheduled')
        ->and($metric->format())->toBe('count')
        ->and($metric->aggregateForTest($query))->toBe(1);

    Carbon::setTestNow();
});

test('orders scheduled metric skips created range without complete boundaries', function () {
    Carbon::setTestNow('2026-07-26 10:00:00');
    $connection = fleetopsOrdersScheduledMetricUseInMemoryConnection();
    $connection->table('orders')->insert([
        [
            'uuid'         => 'scheduled-one',
            'company_uuid' => 'company-scheduled',
            'status'       => 'created',
            'scheduled_at' => '2026-07-27 10:00:00',
            'created_at'   => '2026-07-20 12:00:00',
            'deleted_at'   => null,
        ],
        [
            'uuid'         => 'scheduled-two',
            'company_uuid' => 'company-scheduled',
            'status'       => 'created',
            'scheduled_at' => '2026-08-27 10:00:00',
            'created_at'   => '2026-06-20 12:00:00',
            'deleted_at'   => null,
        ],
    ]);

    $metric = FleetOpsOrdersScheduledMetricProbe::forCompany(fleetopsOrdersScheduledMetricCompany());

    expect($metric->aggregateForTest($metric->queryForTest(new DateTimeImmutable('2026-07-01'), null)))->toBe(2);

    Carbon::setTestNow();
});
