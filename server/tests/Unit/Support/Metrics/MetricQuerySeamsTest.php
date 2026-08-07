<?php

use Fleetbase\FleetOps\Support\Metrics\ActiveLiveOrdersMetric;
use Fleetbase\FleetOps\Support\Metrics\FuelCostsMetric;
use Fleetbase\Models\Company;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the real query seams on the metrics. The behaviour tests override
 * these one-line builders with recorders to keep their fixtures small, which
 * leaves the actual query construction unexercised.
 */
function fleetopsMetricSeamBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $c)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    app()->instance('db.schema', $connection->getSchemaBuilder());
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');
    Illuminate\Support\Facades\Schema::clearResolvedInstance('db.schema');

    // core-api registers this builder macro through its service provider, which
    // does not boot in the harness. The permission directives themselves are
    // core-api's concern; what matters here is that the metric's query reaches
    // it with the live-order scoping already applied.
    if (!Builder::hasGlobalMacro('applyDirectivesForPermissions')) {
        Builder::macro('applyDirectivesForPermissions', function (string $permission) {
            return $this;
        });
    }

    return $connection;
}

function fleetopsMetricSeamCompany(): Company
{
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-metric-seam', 'currency' => 'SGD'], true);

    return $company;
}

function fleetopsMetricSeamInvoke(object $metric, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($metric, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($metric, ...$arguments);
}

test('the legacy bulk api resolves and values every registered metric', function () {
    $connection = fleetopsMetricSeamBoot();
    session(['company' => 'company-metric-seam']);

    $schema  = $connection->getSchemaBuilder();
    $columns = [
        'uuid', 'public_id', 'company_uuid', 'user_uuid', 'driver_assigned_uuid', 'vehicle_assigned_uuid',
        'customer_uuid', 'customer_type', 'payload_uuid', 'tracking_number_uuid', 'order_uuid',
        'service_quote_uuid', 'transaction_uuid', 'status', 'type', 'currency', 'amount', 'distance',
        'time', 'email', 'phone', 'name', 'resolved_at', 'scheduled_at', '_key',
        // Revenue scoping walks transactions back to their subject/context order
        'subject_uuid', 'subject_type', 'context_uuid', 'context_type',
        'parent_transaction_uuid', 'direction', 'voided_at', 'reversed_at',
        // Live-order scoping walks orders -> payload -> waypoints/pickup/dropoff
        'place_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid',
    ];
    foreach ([
        'orders', 'transactions', 'fuel_reports', 'issues', 'drivers', 'contacts', 'users',
        'purchase_rates', 'payloads', 'waypoints', 'tracking_numbers', 'tracking_statuses',
        'companies', 'company_users', 'places',
    ] as $table) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    // No argument means "every registered metric", which is the arm that reads
    // the slug list off the registry instead of normalizing a caller's list.
    $metrics = Fleetbase\FleetOps\Support\Metrics::forCompany(fleetopsMetricSeamCompany())->with()->get();

    expect(array_keys($metrics))->toBe(Fleetbase\FleetOps\Support\Metrics\Registry::slugs())
        ->and($metrics)->each->toBeNumeric();
});

test('fuel costs metric scopes its report query to the company', function () {
    fleetopsMetricSeamBoot();

    $metric = FuelCostsMetric::forCompany(fleetopsMetricSeamCompany());
    $query  = fleetopsMetricSeamInvoke($metric, 'fuelReportQuery', ['company-metric-seam']);

    expect($query)->toBeInstanceOf(Builder::class)
        ->and($query->toSql())->toContain('"company_uuid" = ?')
        ->and($query->getBindings())->toBe(['company-metric-seam']);
});

test('active live orders metric builds the live order query for its company', function () {
    fleetopsMetricSeamBoot();
    session(['company' => 'company-metric-seam']);

    $metric = ActiveLiveOrdersMetric::forCompany(fleetopsMetricSeamCompany());
    $query  = fleetopsMetricSeamInvoke($metric, 'query', [null, null]);

    expect($query)->toBeInstanceOf(Builder::class)
        ->and($query->getBindings())->toContain('company-metric-seam');
});
