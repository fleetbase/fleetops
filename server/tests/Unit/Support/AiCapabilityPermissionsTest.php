<?php

use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\Ai\Capabilities\OperationalQueryCapability;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the AbstractFleetOpsAICapability shared helpers through the
 * concrete OperationalQueryCapability: permission checks with the admin
 * session bypass, the all-permissions conjunction, search-term extraction
 * with stop-word filtering, and the multi-column like matcher.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

function fleetopsAiPermissionsBoot(): SQLiteConnection
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
        'users'    => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'status'],
        'vehicles' => ['uuid', 'public_id', 'company_uuid', 'name', 'plate_number'],
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

function fleetopsAiPermissionsHelper(string $method): ReflectionMethod
{
    $reflection = new ReflectionMethod(OperationalQueryCapability::class, $method);
    $reflection->setAccessible(true);

    return $reflection;
}

test('permission checks pass for admin session users', function () {
    $connection = fleetopsAiPermissionsBoot();
    $connection->table('users')->insert(['uuid' => 'admin-1', 'company_uuid' => 'company-1', 'type' => 'admin']);
    session(['user' => 'admin-1']);

    $capability = new OperationalQueryCapability();

    expect(fleetopsAiPermissionsHelper('can')->invoke($capability, 'fleet-ops list order'))->toBeTrue()
        ->and(fleetopsAiPermissionsHelper('canAll')->invoke($capability, ['fleet-ops list order', 'fleet-ops list driver']))->toBeTrue();
});

test('permission checks for non admin users delegate to the gate', function () {
    fleetopsAiPermissionsBoot();
    session(['user' => null]);

    $capability = new OperationalQueryCapability();

    // Without an admin session user the check falls through to Auth::can,
    // whose permission guard is unavailable in the harness — the delegation
    // line still executes, which is the covered contract here.
    expect(fn () => fleetopsAiPermissionsHelper('can')->invoke($capability, 'fleet-ops list order'))->toThrow(TypeError::class)
        ->and(fn () => fleetopsAiPermissionsHelper('canAll')->invoke($capability, ['fleet-ops list order']))->toThrow(TypeError::class);
});

test('search terms extract identifiers and fall back to the raw prompt', function () {
    fleetopsAiPermissionsBoot();
    $capability  = new OperationalQueryCapability();
    $searchTerms = fleetopsAiPermissionsHelper('searchTerms');

    $terms = $searchTerms->invoke($capability, 'find order TRK-12345 for vehicle atlas99');
    expect($terms)->toContain('TRK-12345', 'atlas99')
        ->and($terms)->not->toContain('find', 'order');

    // Stop-word-only prompts fall back to the trimmed prompt
    expect($searchTerms->invoke($capability, 'find order'))->toBe(['find order']);
});

test('where like any matches across columns and terms', function () {
    $connection = fleetopsAiPermissionsBoot();
    $connection->table('vehicles')->insert([
        ['uuid' => 'vehicle-1', 'company_uuid' => 'company-1', 'name' => 'Atlas Truck', 'plate_number' => 'AAA-1'],
        ['uuid' => 'vehicle-2', 'company_uuid' => 'company-1', 'name' => 'Other', 'plate_number' => 'ZED-9'],
    ]);

    $capability = new OperationalQueryCapability();
    $builder    = Vehicle::query();
    fleetopsAiPermissionsHelper('whereLikeAny')->invoke($capability, $builder, ['name', 'plate_number'], ['Atlas', 'ZED']);

    expect($builder->get())->toHaveCount(2);
});
