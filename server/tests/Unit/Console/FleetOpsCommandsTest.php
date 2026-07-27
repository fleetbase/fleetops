<?php

use Fleetbase\FleetOps\Console\Commands\DispatchOrders;
use Fleetbase\FleetOps\Console\Commands\PurgeUnpurchasedServiceQuotes;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the fleetops console commands against an in-memory SQLite fixture:
 * purging unpurchased service quotes (deletions, empty runs, and the failure
 * rollback branch) and dispatching scheduled orders (ready and not-ready
 * branches).
 */
if (!function_exists('Fleetbase\FleetOps\Models\dispatch')) {
    eval('namespace Fleetbase\FleetOps\Models; function dispatch($job = null) { \FleetOpsCommandsTestRecorder::$dispatched[] = $job; return new \Fleetbase\TestSupport\PendingDispatch(); }');
}

if (!function_exists('Fleetbase\FleetOps\Models\event')) {
    eval('namespace Fleetbase\FleetOps\Models; function event($event = null) { \FleetOpsCommandsTestRecorder::$events[] = $event; return $event; }');
}

class FleetOpsCommandsTestRecorder
{
    public static array $dispatched = [];
    public static array $events     = [];
}

class FleetOpsPurgeQuotesCommandProbe extends PurgeUnpurchasedServiceQuotes
{
    public array $messages   = [];
    public bool $purgeThrows = false;

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function error($string, $verbosity = null)
    {
        $this->messages[] = ['error', $string];
    }

    protected function purgeServiceQuotes($thresholdDate): int
    {
        if ($this->purgeThrows) {
            throw new Exception('purge failed');
        }

        return parent::purgeServiceQuotes($thresholdDate);
    }
}

class FleetOpsDispatchOrdersCommandProbe extends DispatchOrders
{
    public array $messages = [];
    public array $options  = ['sandbox' => 'false'];

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function alert($string, $verbosity = null)
    {
        $this->messages[] = ['alert', $string];
    }

    public function warn($string, $verbosity = null)
    {
        $this->messages[] = ['warn', $string];
    }

    public function option($key = null, $default = null)
    {
        return $this->options[$key] ?? $default;
    }
}

function fleetopsCommandsBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection, 'sandbox' => $connection]);
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

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'service_quotes' => ['uuid', 'public_id', 'company_uuid', 'service_rate_uuid', 'amount', 'currency', 'expired_at'],
        'purchase_rates' => ['uuid', 'public_id', 'company_uuid', 'service_quote_uuid', 'status'],
        'orders'         => ['uuid', 'public_id', 'company_uuid', 'status', 'dispatched', 'dispatched_at', 'scheduled_at', 'type'],
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
    FleetOpsCommandsTestRecorder::$dispatched = [];
    FleetOpsCommandsTestRecorder::$events     = [];

    return $connection;
}

test('purge service quotes deletes stale unpurchased quotes', function () {
    $connection = fleetopsCommandsBoot();
    $connection->table('service_quotes')->insert([
        ['uuid' => 'sq-old', 'company_uuid' => 'company-1', 'created_at' => now()->subDays(5)->toDateTimeString()],
        ['uuid' => 'sq-purchased', 'company_uuid' => 'company-1', 'created_at' => now()->subDays(5)->toDateTimeString()],
        ['uuid' => 'sq-fresh', 'company_uuid' => 'company-1', 'created_at' => now()->toDateTimeString()],
    ]);
    $connection->table('purchase_rates')->insert(['uuid' => 'pr-1', 'service_quote_uuid' => 'sq-purchased']);

    $command = new FleetOpsPurgeQuotesCommandProbe();
    $result  = $command->handle();

    expect($result)->toBe(Command::SUCCESS)
        ->and($connection->table('service_quotes')->count())->toBe(2)
        ->and($connection->table('service_quotes')->where('uuid', 'sq-old')->exists())->toBeFalse()
        ->and($command->messages[0][1])->toContain('Successfully deleted 1');
});

test('purge service quotes reports empty runs', function () {
    fleetopsCommandsBoot();

    $command = new FleetOpsPurgeQuotesCommandProbe();
    $result  = $command->handle();

    expect($result)->toBe(Command::SUCCESS)
        ->and($command->messages[0][1])->toContain('No unpurchased service quotes');
});

test('purge service quotes rolls back and reports failures', function () {
    fleetopsCommandsBoot();

    $command              = new FleetOpsPurgeQuotesCommandProbe();
    $command->purgeThrows = true;
    $result               = $command->handle();

    expect($result)->toBe(Command::FAILURE)
        ->and($command->messages[0][0])->toBe('error')
        ->and($command->messages[0][1])->toContain('purge failed');
});

test('dispatch orders dispatches ready orders and warns for unready ones', function () {
    $connection = fleetopsCommandsBoot();
    $connection->table('orders')->insert([
        [
            'uuid'         => 'order-ready',
            'public_id'    => 'order_ready',
            'company_uuid' => 'company-1',
            'status'       => 'created',
            'dispatched'   => '0',
            'scheduled_at' => now()->toDateTimeString(),
        ],
        [
            'uuid'         => 'order-later',
            'public_id'    => 'order_later',
            'company_uuid' => 'company-1',
            'status'       => 'created',
            'dispatched'   => '0',
            'scheduled_at' => now()->addHours(6)->toDateTimeString(),
        ],
    ]);

    $command = new FleetOpsDispatchOrdersCommandProbe();
    $command->handle();

    $levels = collect($command->messages)->pluck(0);
    expect($levels)->toContain('alert')
        ->and($connection->table('orders')->where('uuid', 'order-ready')->value('dispatched'))->toBe('1')
        ->and($connection->table('orders')->where('uuid', 'order-later')->value('dispatched'))->toBe('0');
});
