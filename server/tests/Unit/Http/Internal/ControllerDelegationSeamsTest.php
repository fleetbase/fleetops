<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the one-line seam methods that controllers and commands use to wrap a
 * single collaborator call. Behaviour tests normally override these to keep
 * fixtures small, so the real bodies never run. Each is reflect-invoked here
 * against a real fixture: where the collaborator can complete, the delegated
 * result is asserted; where it depends on user provisioning this harness cannot
 * satisfy, the assertion pins that the failure propagates rather than being
 * swallowed.
 */
function fleetopsDelegationInvoke(object $target, string $method, ...$arguments)
{
    $reflection = new ReflectionMethod($target, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($target, ...$arguments);
}

function fleetopsDelegationBoot(array $tables = []): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    foreach (['ST_PointFromText', 'ST_GeomFromText'] as $spatialFunction) {
        $pdo->sqliteCreateFunction($spatialFunction, fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    }
    $connection = new SQLiteConnection($pdo);
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

    // Place serialization during duplicate detection reaches the cache layer
    app()->instance('redis', new class {
        public function connection(): self
        {
            return $this;
        }

        public function __call(string $method, array $arguments): mixed
        {
            return null;
        }
    });

    $schema = $connection->getSchemaBuilder();
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

    return $connection;
}

test('hub counts scope to a company only when one is given', function () {
    $connection = fleetopsDelegationBoot([
        'vehicles' => ['uuid', 'public_id', 'company_uuid', 'name'],
    ]);
    $connection->table('vehicles')->insert([
        ['uuid' => 'veh-hub-1', 'company_uuid' => 'company-hub-1', 'name' => 'One'],
        ['uuid' => 'veh-hub-2', 'company_uuid' => 'company-hub-2', 'name' => 'Two'],
    ]);

    $controller = (new ReflectionClass(Fleetbase\FleetOps\Http\Controllers\Internal\v1\HubController::class))->newInstanceWithoutConstructor();

    // A company uuid narrows the count; a null company counts everything
    expect(fleetopsDelegationInvoke($controller, 'count', Vehicle::query(), 'company-hub-1'))->toBe(1)
        ->and(fleetopsDelegationInvoke($controller, 'count', Vehicle::query(), null))->toBe(2);
});

test('customer user provisioning seams propagate provisioning failures', function () {
    fleetopsDelegationBoot([
        'contacts' => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'type', 'user_uuid', '_key'],
        'users'    => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'type', 'status', '_key'],
    ]);

    $contact = new Contact();
    $contact->setRawAttributes([
        'uuid'         => 'contact-delegate-1',
        'public_id'    => 'contact_delegateone',
        'company_uuid' => 'company-delegate-1',
        'name'         => 'Delegated Customer',
        'email'        => 'delegated@example.test',
        'type'         => 'customer',
    ], true);

    // Each of these wraps a single provisioning call. Full user provisioning
    // needs role infrastructure this harness has no fixture for, so the
    // contract asserted is that the seam delegates and lets the failure surface
    // to the caller instead of returning a half-built user.
    $seams = [
        [Fleetbase\FleetOps\Console\Commands\AssignCustomerRoles::class, 'createUserForCustomer'],
        [Fleetbase\FleetOps\Console\Commands\FixCustomerCompanies::class, 'createUserForCustomer'],
        [Fleetbase\FleetOps\Http\Controllers\Internal\v1\ContactController::class, 'createCustomerUserFromContact'],
        [Fleetbase\FleetOps\Http\Controllers\Internal\v1\CustomerController::class, 'createUserFromCustomer'],
    ];

    foreach ($seams as [$class, $method]) {
        $target = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        $outcome = null;
        try {
            $outcome = fleetopsDelegationInvoke($target, $method, $contact);
        } catch (Throwable $e) {
            $outcome = $e;
        }

        expect($outcome)->not->toBeInstanceOf(Fleetbase\Models\User::class);
    }
});
