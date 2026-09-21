<?php

use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The migration that converts profile-only `user` accounts into managed
 * driver/customer accounts, run up and down against in-memory SQLite.
 *
 * An account is converted only when it clearly is a profile login and nothing
 * else; anything that looks like a team member is left alone, and down()
 * reverts only the rows up() converted.
 */
function fleetopsProfileConversionBoot(bool $withPolicies = true): SQLiteConnection
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

        public function table($table, $as = null)
        {
            return $this->c->table($table, $as);
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    app()->instance('db.schema', $connection->getSchemaBuilder());
    DB::clearResolvedInstance('db');
    Schema::clearResolvedInstance('db.schema');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'users'                 => ['uuid', 'type', 'meta'],
        'drivers'               => ['uuid', 'user_uuid'],
        'contacts'              => ['uuid', 'user_uuid', 'type'],
        'companies'             => ['uuid', 'owner_uuid'],
        'company_users'         => ['uuid', 'user_uuid', 'company_uuid'],
        'roles'                 => ['name'],
        'model_has_roles'       => ['role_id', 'model_uuid'],
        'model_has_permissions' => ['permission_id', 'model_uuid'],
    ];
    if ($withPolicies) {
        $tables['model_has_policies'] = ['policy_id', 'model_uuid'];
    }
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    foreach (['Driver', 'Fleet-Ops Customer', 'Administrator'] as $role) {
        $connection->table('roles')->insert(['name' => $role]);
    }

    return $connection;
}

function fleetopsProfileConversionMigration(): object
{
    return require dirname(__DIR__, 4) . '/migrations/2026_09_21_000001_convert_profile_only_users_to_managed_accounts.php';
}

/**
 * Seed a user holding the given profile(s), roles on its company membership
 * (or on the user itself), and optional direct permission or policy.
 */
function fleetopsProfileConversionUser(SQLiteConnection $connection, string $uuid, array $options = []): void
{
    $connection->table('users')->insert(['uuid' => $uuid, 'type' => $options['type'] ?? 'user', 'meta' => $options['meta'] ?? null, 'deleted_at' => $options['deleted_at'] ?? null]);
    $connection->table('company_users')->insert(['uuid' => 'cu-' . $uuid, 'user_uuid' => $uuid, 'company_uuid' => 'company-1']);

    if ($options['driver'] ?? false) {
        $connection->table('drivers')->insert(['uuid' => 'driver-' . $uuid, 'user_uuid' => $uuid]);
    }

    if ($options['customer'] ?? false) {
        $connection->table('contacts')->insert(['uuid' => 'contact-' . $uuid, 'user_uuid' => $uuid, 'type' => 'customer']);
    }

    foreach ($options['roles'] ?? [] as $role) {
        $roleId = $connection->table('roles')->where('name', $role)->value('id');
        $connection->table('model_has_roles')->insert(['role_id' => $roleId, 'model_uuid' => ($options['role_on_user'] ?? false) ? $uuid : 'cu-' . $uuid]);
    }

    if ($options['permission'] ?? false) {
        $connection->table('model_has_permissions')->insert(['permission_id' => 1, 'model_uuid' => 'cu-' . $uuid]);
    }

    if ($options['policy'] ?? false) {
        $connection->table('model_has_policies')->insert(['policy_id' => 1, 'model_uuid' => $uuid]);
    }
}

function fleetopsProfileConversionTypes(SQLiteConnection $connection): array
{
    return $connection->table('users')->orderBy('uuid')->pluck('type', 'uuid')->all();
}

test('up converts profile-only accounts and leaves anything that looks like a team member', function () {
    $connection = fleetopsProfileConversionBoot();

    fleetopsProfileConversionUser($connection, 'a-driver', ['driver' => true, 'roles' => ['Driver'], 'meta' => json_encode(['source' => 'console'])]);
    fleetopsProfileConversionUser($connection, 'b-customer', ['customer' => true, 'roles' => ['Fleet-Ops Customer'], 'role_on_user' => true]);
    fleetopsProfileConversionUser($connection, 'c-owner', ['driver' => true, 'roles' => ['Driver']]);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'owner_uuid' => 'c-owner']);
    fleetopsProfileConversionUser($connection, 'd-extra-role', ['driver' => true, 'roles' => ['Driver', 'Administrator']]);
    fleetopsProfileConversionUser($connection, 'e-permission', ['driver' => true, 'roles' => ['Driver'], 'permission' => true]);
    fleetopsProfileConversionUser($connection, 'f-policy', ['customer' => true, 'roles' => ['Fleet-Ops Customer'], 'policy' => true]);
    fleetopsProfileConversionUser($connection, 'g-both-profiles', ['driver' => true, 'customer' => true, 'roles' => ['Driver']]);
    fleetopsProfileConversionUser($connection, 'h-no-roles', ['driver' => true]);
    fleetopsProfileConversionUser($connection, 'i-wrong-role', ['customer' => true, 'roles' => ['Driver']]);
    fleetopsProfileConversionUser($connection, 'j-no-profile', ['roles' => ['Driver']]);
    fleetopsProfileConversionUser($connection, 'k-admin', ['type' => 'admin', 'driver' => true, 'roles' => ['Driver']]);
    fleetopsProfileConversionUser($connection, 'l-deleted', ['driver' => true, 'roles' => ['Driver'], 'deleted_at' => '2026-01-01 00:00:00']);
    fleetopsProfileConversionUser($connection, 'm-bad-meta', ['driver' => true, 'roles' => ['Driver'], 'meta' => 'not-json']);

    fleetopsProfileConversionMigration()->up();

    expect(fleetopsProfileConversionTypes($connection))->toBe([
        'a-driver'        => 'driver',
        'b-customer'      => 'customer',
        'c-owner'         => 'user',
        'd-extra-role'    => 'user',
        'e-permission'    => 'user',
        'f-policy'        => 'user',
        'g-both-profiles' => 'user',
        'h-no-roles'      => 'user',
        'i-wrong-role'    => 'user',
        'j-no-profile'    => 'user',
        'k-admin'         => 'admin',
        'l-deleted'       => 'user',
        'm-bad-meta'      => 'driver',
    ])
        // The previous type is recorded next to any existing meta
        ->and(json_decode($connection->table('users')->where('uuid', 'a-driver')->value('meta'), true))->toBe(['source' => 'console', 'previous_type' => 'user'])
        ->and(json_decode($connection->table('users')->where('uuid', 'm-bad-meta')->value('meta'), true))->toBe(['previous_type' => 'user']);
});

test('down reverts only the accounts up converted', function () {
    $connection = fleetopsProfileConversionBoot();

    fleetopsProfileConversionUser($connection, 'a-driver', ['driver' => true, 'roles' => ['Driver'], 'meta' => json_encode(['source' => 'console'])]);
    fleetopsProfileConversionUser($connection, 'b-customer', ['customer' => true, 'roles' => ['Fleet-Ops Customer']]);
    // Managed accounts created after the change carry no previous type
    fleetopsProfileConversionUser($connection, 'c-native-driver', ['type' => 'driver', 'driver' => true, 'meta' => json_encode(['source' => 'app'])]);
    // A previous type other than `user` is not this migration's
    fleetopsProfileConversionUser($connection, 'd-other-previous', ['type' => 'customer', 'customer' => true, 'meta' => json_encode(['previous_type' => 'contact'])]);

    $migration = fleetopsProfileConversionMigration();
    $migration->up();
    $migration->down();

    expect(fleetopsProfileConversionTypes($connection))->toBe([
        'a-driver'         => 'user',
        'b-customer'       => 'user',
        'c-native-driver'  => 'driver',
        'd-other-previous' => 'customer',
    ])
        ->and(json_decode($connection->table('users')->where('uuid', 'a-driver')->value('meta'), true))->toBe(['source' => 'console'])
        ->and(json_decode($connection->table('users')->where('uuid', 'b-customer')->value('meta'), true))->toBe([])
        ->and(json_decode($connection->table('users')->where('uuid', 'd-other-previous')->value('meta'), true))->toBe(['previous_type' => 'contact']);
});

test('up and down do nothing when the tables they read are missing', function () {
    $connection = fleetopsProfileConversionBoot(false);
    fleetopsProfileConversionUser($connection, 'a-driver', ['driver' => true, 'roles' => ['Driver']]);

    $migration = fleetopsProfileConversionMigration();
    $migration->up();

    expect(fleetopsProfileConversionTypes($connection))->toBe(['a-driver' => 'user']);

    $connection->table('users')->where('uuid', 'a-driver')->update(['type' => 'driver', 'meta' => json_encode(['previous_type' => 'user'])]);
    $connection->getSchemaBuilder()->drop('users');

    // Without a users table there is nothing to revert
    $migration->down();

    expect($connection->getSchemaBuilder()->hasTable('users'))->toBeFalse();
});
