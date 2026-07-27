<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController;
use Fleetbase\FleetOps\Http\Requests\DriverSimulationRequest;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Covers the driver-lookup and organization endpoints of the API
 * DriverController through an in-memory SQLite fixture. These paths were
 * previously unreachable because their not-found branches return
 * response()->apiError(...)/response()->json(..., 404); the bootstrap now
 * provides those shims so the real controller error handling executes.
 */
class FleetOpsApiDriverLookupDatabase
{
    public function __construct(public SQLiteConnection $connection)
    {
    }

    public function connection($name = null): SQLiteConnection
    {
        return $this->connection;
    }

    public function __call($method, $arguments)
    {
        return $this->connection->{$method}(...$arguments);
    }
}

function fleetopsApiDriverLookupBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsApiDriverLookupDatabase($connection));

    $schema = $connection->getSchemaBuilder();
    $schema->create('users', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('company_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('drivers', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('internal_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('internal_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('companies', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    return $connection;
}

function fleetopsApiDriverLookupSeedDriver(SQLiteConnection $connection): void
{
    $connection->table('users')->insert(['uuid' => 'user-uuid', 'company_uuid' => 'company-uuid']);
    $connection->table('drivers')->insert([
        'uuid'         => 'driver-uuid',
        'public_id'    => 'driver_test',
        'company_uuid' => 'company-uuid',
        'user_uuid'    => 'user-uuid',
    ]);
    $connection->table('companies')->insert(['uuid' => 'company-uuid', 'public_id' => 'company_test']);
}

test('current organization endpoint returns a 404 error when the driver is missing', function () {
    fleetopsApiDriverLookupBoot();

    $response = (new DriverController())->currentOrganization('missing', Request::create('/x', 'GET'));

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(404)
        ->and($response->getData(true))->toBe(['error' => 'Driver resource not found.']);
});

test('list organizations endpoint returns a 404 error when the driver is missing', function () {
    fleetopsApiDriverLookupBoot();

    $response = (new DriverController())->listOrganizations('missing', Request::create('/x', 'GET'));

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(404)
        ->and($response->getData(true))->toBe(['error' => 'Driver resource not found.']);
});

test('simulate endpoint returns a 404 error when the driver is missing', function () {
    fleetopsApiDriverLookupBoot();

    $request  = DriverSimulationRequest::create('/x', 'POST', ['start' => null, 'end' => null]);
    $response = (new DriverController())->simulate('missing', $request);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(404)
        ->and($response->getData(true))->toBe(['error' => 'Driver resource not found.']);
});

test('simulate endpoint returns a 404 error when the referenced order is missing', function () {
    $connection = fleetopsApiDriverLookupBoot();
    fleetopsApiDriverLookupSeedDriver($connection);

    $request  = DriverSimulationRequest::create('/x', 'POST', ['order' => 'order_missing']);
    $response = (new DriverController())->simulate('driver_test', $request);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(404)
        ->and($response->getData(true))->toBe(['error' => 'Order resource not found.']);
});

test('driver company resolver reads the company from the matching driver profile', function () {
    $connection = fleetopsApiDriverLookupBoot();
    fleetopsApiDriverLookupSeedDriver($connection);

    $user = new Fleetbase\Models\User();
    $user->setRawAttributes(['uuid' => 'user-uuid', 'company_uuid' => 'company-uuid'], true);

    $reflection = new ReflectionMethod(DriverController::class, 'getDriverCompanyFromUser');
    $reflection->setAccessible(true);
    $company = $reflection->invoke(null, $user);

    expect($company)->toBeInstanceOf(Fleetbase\Models\Company::class)
        ->and($company->uuid)->toBe('company-uuid');
});
