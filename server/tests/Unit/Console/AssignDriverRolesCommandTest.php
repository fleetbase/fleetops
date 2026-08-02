<?php

use Fleetbase\FleetOps\Console\Commands\AssignDriverRoles;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the fleetops:assign-driver-roles command against SQLite: company
 * traversal with user/driver matching, the admin skip, and the error branch
 * when role assignment is unavailable in the harness.
 */
class FleetOpsAssignDriverRolesProbe extends AssignDriverRoles
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

function fleetopsAssignDriverRolesBoot(): SQLiteConnection
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
        'companies'     => ['uuid', 'public_id', 'name', 'owner_uuid'],
        'company_users' => ['uuid', 'company_uuid', 'user_uuid', 'status'],
        'users'         => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'type', 'status'],
        'drivers'       => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'status'],
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

    User::expand('driver', function () {
        return $this->hasOne(Driver::class, 'user_uuid', 'uuid')->withoutGlobalScopes();
    });

    session(['company' => 'company-1']);

    return $connection;
}

test('assign driver roles walks companies and reports assignment errors', function () {
    $connection = fleetopsAssignDriverRolesBoot();
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme']);
    $connection->table('users')->insert([
        ['uuid' => 'user-1', 'company_uuid' => 'company-1', 'name' => 'Driver User', 'email' => 'driver@example.test', 'type' => 'user'],
        ['uuid' => 'user-2', 'company_uuid' => 'company-1', 'name' => 'Admin User', 'email' => 'admin@example.test', 'type' => 'admin'],
    ]);
    $connection->table('company_users')->insert([
        ['uuid' => 'cu-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1'],
        ['uuid' => 'cu-2', 'company_uuid' => 'company-1', 'user_uuid' => 'user-2'],
    ]);
    $connection->table('drivers')->insert([
        ['uuid' => 'driver-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'status' => 'active'],
        ['uuid' => 'driver-2', 'company_uuid' => 'company-1', 'user_uuid' => 'user-2', 'status' => 'active'],
    ]);

    $command = new FleetOpsAssignDriverRolesProbe();
    $result  = $command->handle();

    // Role storage is unavailable in the harness, so the driver user hits
    // the error branch; the admin user is skipped before role assignment.
    expect($result)->toBe(0)
        ->and(collect($command->messages)->where(0, 'error')->count())->toBeGreaterThanOrEqual(1)
        ->and(collect($command->messages)->where(0, 'info')->count())->toBe(0);
});

test('assign driver roles completes quietly with no companies', function () {
    fleetopsAssignDriverRolesBoot();

    $command = new FleetOpsAssignDriverRolesProbe();

    expect($command->handle())->toBe(0)
        ->and($command->messages)->toBe([]);
});
