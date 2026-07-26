<?php

use Fleetbase\FleetOps\Support\Metrics\AvgOrderValueMetric;
use Fleetbase\Models\Company;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

class FleetOpsAvgOrderValueMetricProbe extends AvgOrderValueMetric
{
    public function queryForTest(?DateTimeInterface $start, ?DateTimeInterface $end): Builder
    {
        return $this->query($start, $end);
    }

    public function aggregateForTest($query): float
    {
        return $this->aggregate($query);
    }
}

class FleetOpsAvgOrderValueMetricDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }
}

function fleetopsAvgOrderValueMetricUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table orders (uuid varchar(64), company_uuid varchar(64), status varchar(64), transaction_uuid varchar(64) null, created_at datetime null, deleted_at datetime null)');
    $connection->statement('create table transactions (uuid varchar(64), company_uuid varchar(64), currency varchar(8), direction varchar(16), status varchar(32), amount numeric, subject_uuid varchar(64) null, subject_type varchar(255) null, context_uuid varchar(64) null, context_type varchar(255) null, parent_transaction_uuid varchar(64) null, voided_at datetime null, reversed_at datetime null, created_at datetime null, deleted_at datetime null)');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsAvgOrderValueMetricDatabaseProbe($connection));
    app()->instance('db.schema', $connection->getSchemaBuilder());

    return $connection;
}

function fleetopsAvgOrderValueMetricCompany(): Company
{
    $company = new Company();
    $company->setRawAttributes([
        'uuid'     => 'company-average-order',
        'currency' => 'SGD',
    ], true);

    return $company;
}

test('average order value metric divides active revenue by completed orders in the period', function () {
    $connection = fleetopsAvgOrderValueMetricUseInMemoryConnection();
    $connection->table('orders')->insert([
        [
            'uuid'             => 'completed-one',
            'company_uuid'     => 'company-average-order',
            'status'           => 'completed',
            'transaction_uuid' => 'revenue-one',
            'created_at'       => '2026-07-10 10:00:00',
            'deleted_at'       => null,
        ],
        [
            'uuid'             => 'completed-two',
            'company_uuid'     => 'company-average-order',
            'status'           => 'completed',
            'transaction_uuid' => 'revenue-two',
            'created_at'       => '2026-07-11 10:00:00',
            'deleted_at'       => null,
        ],
        [
            'uuid'             => 'created-order',
            'company_uuid'     => 'company-average-order',
            'status'           => 'created',
            'transaction_uuid' => 'ignored-status',
            'created_at'       => '2026-07-12 10:00:00',
            'deleted_at'       => null,
        ],
        [
            'uuid'             => 'completed-outside-period',
            'company_uuid'     => 'company-average-order',
            'status'           => 'completed',
            'transaction_uuid' => 'ignored-period',
            'created_at'       => '2026-06-10 10:00:00',
            'deleted_at'       => null,
        ],
        [
            'uuid'             => 'completed-other-company',
            'company_uuid'     => 'other-company',
            'status'           => 'completed',
            'transaction_uuid' => 'ignored-company',
            'created_at'       => '2026-07-10 10:00:00',
            'deleted_at'       => null,
        ],
    ]);
    $connection->table('transactions')->insert([
        [
            'uuid'                    => 'revenue-one',
            'company_uuid'            => 'company-average-order',
            'currency'                => 'SGD',
            'direction'               => 'credit',
            'status'                  => 'success',
            'amount'                  => 75.25,
            'subject_uuid'            => null,
            'subject_type'            => null,
            'context_uuid'            => null,
            'context_type'            => null,
            'parent_transaction_uuid' => null,
            'voided_at'               => null,
            'reversed_at'             => null,
            'created_at'              => '2026-07-10 11:00:00',
            'deleted_at'              => null,
        ],
        [
            'uuid'                    => 'revenue-two',
            'company_uuid'            => 'company-average-order',
            'currency'                => 'SGD',
            'direction'               => 'credit',
            'status'                  => 'success',
            'amount'                  => 24.75,
            'subject_uuid'            => null,
            'subject_type'            => null,
            'context_uuid'            => null,
            'context_type'            => null,
            'parent_transaction_uuid' => null,
            'voided_at'               => null,
            'reversed_at'             => null,
            'created_at'              => '2026-07-11 11:00:00',
            'deleted_at'              => null,
        ],
        [
            'uuid'                    => 'ignored-currency',
            'company_uuid'            => 'company-average-order',
            'currency'                => 'USD',
            'direction'               => 'credit',
            'status'                  => 'success',
            'amount'                  => 900,
            'subject_uuid'            => null,
            'subject_type'            => null,
            'context_uuid'            => null,
            'context_type'            => null,
            'parent_transaction_uuid' => null,
            'voided_at'               => null,
            'reversed_at'             => null,
            'created_at'              => '2026-07-10 11:00:00',
            'deleted_at'              => null,
        ],
        [
            'uuid'                    => 'ignored-period',
            'company_uuid'            => 'company-average-order',
            'currency'                => 'SGD',
            'direction'               => 'credit',
            'status'                  => 'success',
            'amount'                  => 200,
            'subject_uuid'            => null,
            'subject_type'            => null,
            'context_uuid'            => null,
            'context_type'            => null,
            'parent_transaction_uuid' => null,
            'voided_at'               => null,
            'reversed_at'             => null,
            'created_at'              => '2026-06-10 11:00:00',
            'deleted_at'              => null,
        ],
    ]);

    $metric = FleetOpsAvgOrderValueMetricProbe::forCompany(fleetopsAvgOrderValueMetricCompany())
        ->between(
            new DateTimeImmutable('2026-07-01 00:00:00'),
            new DateTimeImmutable('2026-07-31 23:59:59')
        );

    expect(AvgOrderValueMetric::slug())->toBe('avg_order_value')
        ->and($metric->format())->toBe('money')
        ->and($metric->currency())->toBe('SGD')
        ->and($metric->value())->toBe(50.0);
});

test('average order value metric returns zero without completed orders', function () {
    $connection = fleetopsAvgOrderValueMetricUseInMemoryConnection();
    $connection->table('orders')->insert([
        'uuid'             => 'created-only',
        'company_uuid'     => 'company-average-order',
        'status'           => 'created',
        'transaction_uuid' => 'created-revenue',
        'created_at'       => '2026-07-10 10:00:00',
        'deleted_at'       => null,
    ]);

    $metric = FleetOpsAvgOrderValueMetricProbe::forCompany(fleetopsAvgOrderValueMetricCompany());

    expect($metric->aggregateForTest($metric->queryForTest(null, null)))->toBe(0.0);
});
