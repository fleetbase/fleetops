<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

use Fleetbase\FleetOps\Http\Resources\v1\Driver as DriverResource;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the v1 Order and Driver resource transformation seams: morph
 * resource resolution with the customer user stripped, and the driver
 * order-count, current-order reference and jobs collection helpers.
 */
class FleetOpsOrderResourceProbe extends OrderResource
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

class FleetOpsDriverResourceProbe extends DriverResource
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

function fleetopsResourceBoot(): SQLiteConnection
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
        'orders'              => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'customer_uuid', 'customer_type', 'tracking_number_uuid', 'driver_assigned_uuid', 'status', 'type', 'tracking', 'meta'],
        'contacts'            => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'name', 'email', 'type'],
        'drivers'             => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'current_job_uuid', 'status'],
        'users'               => ['uuid', 'public_id', 'company_uuid', 'name', 'type'],
        'places'              => ['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'owner_type', 'name', 'location'],
        'vehicles'            => ['uuid', 'public_id', 'company_uuid', 'driver_uuid', 'name'],
        'payloads'            => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'meta'],
        'tracking_numbers'    => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', '_key'],
        'custom_fields'       => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'name', 'label'],
        'custom_field_values' => ['uuid', 'public_id', 'company_uuid', 'custom_field_uuid', 'subject_uuid', 'subject_type', 'value', 'value_type'],
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

    app()->instance('request', Request::create('/v1/orders', 'GET'));
    session(['company' => 'company-1']);

    return $connection;
}

test('order resources transform morph customers without nested users', function () {
    $connection = fleetopsResourceBoot();
    $connection->table('contacts')->insert(['uuid' => 'contact-res-1', 'public_id' => 'contact_resone1', 'company_uuid' => 'company-1', 'name' => 'Resource Customer', 'type' => 'customer']);

    $contact = Contact::where('uuid', 'contact-res-1')->first();
    $probe   = new FleetOpsOrderResourceProbe(new Order());

    // Null morphs return null, models resolve through their http resources
    expect($probe->callHelper('transformMorphResource', null))->toBeNull();

    $resolved = $probe->callHelper('transformOrderCustomerResource', $contact);
    expect($resolved)->toBeArray()
        ->and($resolved)->not->toHaveKey('user');
});

test('driver resources count orders and reference current jobs', function () {
    $connection = fleetopsResourceBoot();
    $connection->table('users')->insert(['uuid' => 'user-res-1', 'company_uuid' => 'company-1', 'name' => 'Resource Driver']);
    $connection->table('drivers')->insert(['uuid' => 'driver-res-1', 'public_id' => 'driver_resone1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-res-1', 'current_job_uuid' => 'order-res-1']);
    $connection->table('orders')->insert([
        ['uuid' => 'order-res-1', 'public_id' => 'order_resone1', 'company_uuid' => 'company-1', 'driver_assigned_uuid' => 'driver-res-1', 'status' => 'created', 'tracking' => null],
        ['uuid' => 'order-res-2', 'public_id' => 'order_restwo2', 'company_uuid' => 'company-1', 'driver_assigned_uuid' => 'driver-res-1', 'status' => 'created', 'tracking' => null],
    ]);

    $driver = Driver::where('uuid', 'driver-res-1')->first();
    $probe  = new FleetOpsDriverResourceProbe($driver);

    expect($probe->callHelper('assignedOrdersCount'))->toBe(2)
        ->and($probe->callHelper('currentOrderReference'))->toBe('order_resone1')
        ->and($probe->callHelper('getJobs'))->not->toBeNull();
});
