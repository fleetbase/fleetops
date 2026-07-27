<?php

use Fleetbase\FleetOps\Http\Resources\v1\Customer as CustomerResource;
use Fleetbase\FleetOps\Models\Customer;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the public Customer API resource: full array serialization with the
 * customer_-prefixed public id, the orders count subquery, and the company
 * payload projection with currency resolution and missing-company fallback.
 */
function fleetopsCustomerResourceBoot(): SQLiteConnection
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
        'contacts'  => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'place_uuid', 'photo_uuid', 'name', 'title', 'email', 'phone', 'type', 'slug', 'meta'],
        'orders'    => ['uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'status'],
        'companies' => ['uuid', 'public_id', 'name', 'currency', 'country', 'phone'],
        'places'    => ['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'name', 'street1', 'city', 'country', 'location'],
        'settings'  => ['key', 'value'],
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

function fleetopsCustomerResourceCustomer(array $attributes = []): Customer
{
    $customer = new Customer();
    $customer->setRawAttributes(array_merge([
        'uuid'         => 'contact-1',
        'public_id'    => 'contact_abc',
        'company_uuid' => 'company-1',
        'name'         => 'Customer A',
        'email'        => 'customer@example.test',
        'type'         => 'customer',
    ], $attributes), true);
    $customer->exists = true;

    return $customer;
}

test('customer resource serializes public payload with orders count', function () {
    $connection = fleetopsCustomerResourceBoot();
    $connection->table('companies')->insert(['uuid' => 'company-1', 'public_id' => 'company_test', 'name' => 'Acme', 'currency' => 'sgd', 'country' => 'SG', 'phone' => '+65 1234']);
    $connection->table('orders')->insert([
        ['uuid' => 'order-1', 'customer_uuid' => 'contact-1', 'company_uuid' => 'company-1', 'status' => 'created'],
        ['uuid' => 'order-2', 'customer_uuid' => 'contact-1', 'company_uuid' => 'company-1', 'status' => 'completed'],
        ['uuid' => 'order-3', 'customer_uuid' => 'contact-other', 'company_uuid' => 'company-1', 'status' => 'created'],
    ]);

    $payload = (new CustomerResource(fleetopsCustomerResourceCustomer()))->toArray(new Request());

    expect($payload['id'])->toBe('customer_abc')
        ->and($payload['name'])->toBe('Customer A')
        ->and($payload['orders_count'])->toBe(2)
        ->and($payload['company']['id'])->toBe('company_test')
        ->and($payload['company']['currency'])->toBe('SGD')
        ->and($payload['company']['country'])->toBe('SG');
});

test('customer resource omits company payload when company is missing', function () {
    fleetopsCustomerResourceBoot();

    $payload = (new CustomerResource(fleetopsCustomerResourceCustomer([
        'uuid'         => 'contact-2',
        'public_id'    => 'contact_def',
        'company_uuid' => 'company-missing',
    ])))->toArray(new Request());

    expect($payload['company'])->toBeNull()
        ->and($payload['orders_count'])->toBe(0);
});
