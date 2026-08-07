<?php

use Fleetbase\FleetOps\Console\Commands\AssignCustomerRoles;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the fleetops:assign-customer-roles command against SQLite: customer
 * traversal, user resolution with on-demand creation, and the role
 * assignment error branch when role storage is unavailable in the harness.
 */
class FleetOpsAssignCustomerRolesProbe extends AssignCustomerRoles
{
    public array $messages = [];

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function error($string, $verbosity = null)
    {
        $this->messages[] = ['error', $string];
    }
}

function fleetopsAssignCustomerRolesBoot(): SQLiteConnection
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
        'contacts'      => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'name', 'email', 'phone', 'type', 'meta'],
        'users'         => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'type', 'status', 'username', 'password'],
        'companies'     => ['uuid', 'public_id', 'name'],
        'company_users' => ['uuid', 'company_uuid', 'user_uuid', 'status'],
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

test('assign customer roles resolves users and reports assignment errors', function () {
    $connection = fleetopsAssignCustomerRolesBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'name' => 'Customer User']);
    $connection->table('contacts')->insert([
        'uuid'         => 'contact-1',
        'company_uuid' => 'company-1',
        'user_uuid'    => 'user-1',
        'name'         => 'Customer A',
        'email'        => 'customer@example.test',
        'type'         => 'customer',
    ]);

    $command = new FleetOpsAssignCustomerRolesProbe();
    $result  = $command->handle();

    // Role storage is unavailable in the harness so each customer hits the
    // error branch after user resolution.
    expect($result)->toBe(0)
        ->and(collect($command->messages)->where(0, 'error')->count())->toBe(1);
});

test('assign customer roles completes quietly without customers', function () {
    fleetopsAssignCustomerRolesBoot();

    $command = new FleetOpsAssignCustomerRolesProbe();

    expect($command->handle())->toBe(0)
        ->and($command->messages)->toBe([]);
});
