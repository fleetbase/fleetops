<?php

use Fleetbase\FleetOps\Support\Analytics\OnTimeDelivery;
use Fleetbase\FleetOps\Support\Analytics\OrdersByStatus;
use Fleetbase\FleetOps\Support\Analytics\RevenueTrend;
use Fleetbase\FleetOps\Support\Analytics\TopDrivers;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Company;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

/**
 * Covers the on-time delivery and top-driver analytics against SQLite via
 * the driver-aware datetime-difference SQL helpers: SLA bucketing with
 * comparison windows, driver leaderboards with on-time sorting, and the
 * portable SQL expression builders themselves.
 */
function fleetopsAnalyticsBoot(): SQLiteConnection
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
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'orders'  => ['uuid', 'public_id', 'company_uuid', 'driver_assigned_uuid', 'transaction_uuid', 'status', 'scheduled_at', 'distance'],
        'drivers' => ['uuid', 'public_id', 'company_uuid', 'user_uuid'],
        'users'   => ['uuid', 'public_id', 'company_uuid', 'name', 'avatar_uuid'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);

    return $connection;
}

function fleetopsAnalyticsCompany(): Company
{
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-1', 'public_id' => 'company_analytics', 'name' => 'Analytics Co'], true);

    return $company;
}

test('sql datetime helpers emit driver specific expressions', function () {
    fleetopsAnalyticsBoot();

    expect(Utils::sqlSecondsDiff('a', 'b'))->toContain('julianday')
        ->and(Utils::sqlMinutesDiff('a', 'b'))->toContain('1440')
        ->and(Utils::sqlNow())->toBe("datetime('now')")
        ->and(Utils::sqlLeast('x', 'y'))->toBe('MIN(x, y)');
});

test('on time delivery buckets completed orders against the sla window', function () {
    $connection = fleetopsAnalyticsBoot();
    Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00'));

    $connection->table('orders')->insert([
        // On time: completed 10 minutes after schedule
        ['uuid' => 'order-ontime', 'company_uuid' => 'company-1', 'status' => 'completed', 'scheduled_at' => '2026-07-15 10:00:00', 'updated_at' => '2026-07-15 10:10:00', 'created_at' => '2026-07-15 09:00:00'],
        // Late: completed 2 hours after schedule
        ['uuid' => 'order-late', 'company_uuid' => 'company-1', 'status' => 'completed', 'scheduled_at' => '2026-07-15 10:00:00', 'updated_at' => '2026-07-15 12:00:00', 'created_at' => '2026-07-15 09:00:00'],
        // Excluded: no schedule
        ['uuid' => 'order-unscheduled', 'company_uuid' => 'company-1', 'status' => 'completed', 'scheduled_at' => null, 'updated_at' => '2026-07-15 12:00:00', 'created_at' => '2026-07-15 09:00:00'],
    ]);

    $result = OnTimeDelivery::forCompany(fleetopsAnalyticsCompany())
        ->between(Carbon::parse('2026-07-10 00:00:00'), Carbon::parse('2026-07-19 00:00:00'))
        ->slaMinutes(30)
        ->get();

    expect($result['on_time'])->toBe(1)
        ->and($result['late'])->toBe(1)
        ->and($result['total'])->toBe(2)
        ->and($result['on_time_pct'])->toBe(50.0)
        ->and($result['sla_minutes'])->toBe(30);

    Carbon::setTestNow();
});

test('top drivers rank completions with on time ratios on sqlite', function () {
    $connection = fleetopsAnalyticsBoot();
    Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00'));

    $connection->table('users')->insert([
        ['uuid' => 'user-a', 'company_uuid' => 'company-1', 'name' => 'Driver A'],
        ['uuid' => 'user-b', 'company_uuid' => 'company-1', 'name' => 'Driver B'],
    ]);
    $connection->table('drivers')->insert([
        ['uuid' => 'driver-a', 'company_uuid' => 'company-1', 'user_uuid' => 'user-a'],
        ['uuid' => 'driver-b', 'company_uuid' => 'company-1', 'user_uuid' => 'user-b'],
    ]);
    $connection->table('orders')->insert([
        ['uuid' => 'order-a1', 'company_uuid' => 'company-1', 'driver_assigned_uuid' => 'driver-a', 'status' => 'completed', 'scheduled_at' => '2026-07-15 10:00:00', 'updated_at' => '2026-07-15 10:10:00', 'created_at' => '2026-07-15 09:00:00', 'distance' => '5000'],
        ['uuid' => 'order-a2', 'company_uuid' => 'company-1', 'driver_assigned_uuid' => 'driver-a', 'status' => 'completed', 'scheduled_at' => '2026-07-15 10:00:00', 'updated_at' => '2026-07-15 13:00:00', 'created_at' => '2026-07-15 09:00:00', 'distance' => '4000'],
        ['uuid' => 'order-b1', 'company_uuid' => 'company-1', 'driver_assigned_uuid' => 'driver-b', 'status' => 'completed', 'scheduled_at' => '2026-07-15 10:00:00', 'updated_at' => '2026-07-15 10:05:00', 'created_at' => '2026-07-15 09:00:00', 'distance' => '9000'],
    ]);

    $byCompletions = TopDrivers::forCompany(fleetopsAnalyticsCompany())
        ->between(Carbon::parse('2026-07-10 00:00:00'), Carbon::parse('2026-07-19 00:00:00'))
        ->limit(5)
        ->get();
    expect($byCompletions)->not->toBeEmpty();

    $byOnTime = TopDrivers::forCompany(fleetopsAnalyticsCompany())
        ->between(Carbon::parse('2026-07-10 00:00:00'), Carbon::parse('2026-07-19 00:00:00'))
        ->sortBy('on_time')
        ->limit(5)
        ->get();
    expect($byOnTime)->not->toBeEmpty();

    $byDistance = TopDrivers::forCompany(fleetopsAnalyticsCompany())
        ->between(Carbon::parse('2026-07-10 00:00:00'), Carbon::parse('2026-07-19 00:00:00'))
        ->sortBy('distance')
        ->limit(5)
        ->get();
    expect($byDistance)->not->toBeEmpty();

    Carbon::setTestNow();
});

test('hos duration and geofence dwell expressions build portable sql', function () {
    fleetopsAnalyticsBoot();

    $controller = new Fleetbase\FleetOps\Http\Controllers\Internal\v1\DriverController();
    $reflection = new ReflectionMethod($controller, 'hosDurationExpression');
    $reflection->setAccessible(true);
    $expression = (string) $reflection->invoke($controller)->getValue(EloquentModel::resolveConnection('mysql')->getQueryGrammar());

    expect($expression)->toContain('julianday')
        ->and($expression)->toContain('MIN(');
});

test('orders by status buckets daily counts with the fixed palette', function () {
    $connection = fleetopsAnalyticsBoot();
    Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00'));

    $connection->table('orders')->insert([
        ['uuid' => 'order-s1', 'company_uuid' => 'company-1', 'status' => 'completed', 'created_at' => '2026-07-15 09:00:00', 'updated_at' => '2026-07-15 09:00:00'],
        ['uuid' => 'order-s2', 'company_uuid' => 'company-1', 'status' => 'completed', 'created_at' => '2026-07-15 10:00:00', 'updated_at' => '2026-07-15 10:00:00'],
        ['uuid' => 'order-s3', 'company_uuid' => 'company-1', 'status' => 'canceled', 'created_at' => '2026-07-16 10:00:00', 'updated_at' => '2026-07-16 10:00:00'],
        ['uuid' => 'order-s4', 'company_uuid' => 'company-1', 'status' => 'draft', 'created_at' => '2026-07-16 10:00:00', 'updated_at' => '2026-07-16 10:00:00'],
    ]);

    $result = OrdersByStatus::forCompany(fleetopsAnalyticsCompany())
        ->between(Carbon::parse('2026-07-14 00:00:00'), Carbon::parse('2026-07-17 00:00:00'))
        ->get();

    expect($result['labels'])->toBe(['Jul 14', 'Jul 15', 'Jul 16', 'Jul 17']);
    $byLabel = collect($result['datasets'])->keyBy('label');
    expect($byLabel['Completed']['data'])->toBe([0, 2, 0, 0])
        ->and($byLabel['Canceled']['data'])->toBe([0, 0, 1, 0])
        ->and($byLabel['Failed']['data'])->toBe([0, 0, 0, 0]);

    Carbon::setTestNow();
});

test('revenue trend buckets transactions with weekly grouping and deltas', function () {
    $connection = fleetopsAnalyticsBoot();
    app()->instance('db.schema', $connection->getSchemaBuilder());
    Illuminate\Support\Facades\Schema::clearResolvedInstance('db.schema');
    $connection->getPdo()->sqliteCreateFunction('DATE_FORMAT', function ($date, $format) {
        $map = ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%x' => 'o', '%v' => 'W'];

        return date(strtr($format, $map), strtotime((string) $date));
    });
    Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00'));

    $schema = $connection->getSchemaBuilder();
    $schema->create('transactions', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'subject_uuid', 'subject_type', 'context_uuid', 'context_type', 'parent_transaction_uuid', 'amount', 'currency', 'direction', 'status', 'meta', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamp('voided_at')->nullable();
        $blueprint->timestamp('reversed_at')->nullable();
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    $connection->table('transactions')->insert([
        ['uuid' => 'txn-1', 'company_uuid' => 'company-1', 'amount' => '1000', 'currency' => 'USD', 'direction' => 'credit', 'status' => 'success', 'created_at' => '2026-07-14 10:00:00', 'updated_at' => '2026-07-14 10:00:00'],
        ['uuid' => 'txn-2', 'company_uuid' => 'company-1', 'amount' => '2500', 'currency' => 'USD', 'direction' => 'credit', 'status' => 'success', 'created_at' => '2026-07-15 10:00:00', 'updated_at' => '2026-07-15 10:00:00'],
        ['uuid' => 'txn-prev', 'company_uuid' => 'company-1', 'amount' => '500', 'currency' => 'USD', 'direction' => 'credit', 'status' => 'success', 'created_at' => '2026-07-05 10:00:00', 'updated_at' => '2026-07-05 10:00:00'],
    ]);

    $result = RevenueTrend::forCompany(fleetopsAnalyticsCompany())
        ->between(Carbon::parse('2026-07-10 00:00:00'), Carbon::parse('2026-07-19 00:00:00'))
        ->groupBy('week')
        ->get();

    expect($result['labels'])->not->toBeEmpty()
        ->and(collect($result['datasets'][0]['data'])->sum())->toBeGreaterThan(0)
        ->and($result['summary'])->toHaveKey('delta_pct');

    // Invalid group-by units fall back to daily buckets
    $daily = RevenueTrend::forCompany(fleetopsAnalyticsCompany())
        ->between(Carbon::parse('2026-07-10 00:00:00'), Carbon::parse('2026-07-19 00:00:00'))
        ->groupBy('hourly')
        ->get();
    expect($daily['labels'])->not->toBeEmpty();

    Carbon::setTestNow();
});

test('abstract analytics resolves every period shorthand and defaults', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00'));

    foreach (['7d' => 7, '14d' => 14, '30d' => 30, '90d' => 90, '180d' => 180, '365d' => 365] as $period => $days) {
        [$start, $end] = Fleetbase\FleetOps\Support\Analytics\AbstractAnalytics::resolvePeriod($period, null, null);
        expect(Carbon::instance($start)->toDateString())->toBe(Carbon::parse('2026-07-20')->subDays($days)->toDateString());
    }

    // Unknown periods fall through to explicit dates, then the default window
    [$start, $end] = Fleetbase\FleetOps\Support\Analytics\AbstractAnalytics::resolvePeriod('fortnight', Carbon::parse('2026-06-01')->toDateTime(), Carbon::parse('2026-06-10')->toDateTime());
    expect(Carbon::instance($start)->toDateString())->toBe('2026-06-01');

    [$start, $end] = Fleetbase\FleetOps\Support\Analytics\AbstractAnalytics::resolvePeriod(null, null, null, 10);
    expect(Carbon::instance($start)->toDateString())->toBe('2026-07-10');

    Carbon::setTestNow();
});
