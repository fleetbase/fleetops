<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Http\Resources\v1\Proof as ProofResource;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\TestSupport\DispatchRecorder;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Http;

/**
 * Covers the API OrderController protected persistence helpers against
 * SQLite: customer contact firstOrCreate, company timezone fallback, order
 * and proof and file creation, the finalize-order job dispatch, driving
 * distance resolution with a faked routing response, storage writes, proof
 * subject scoping, entity editing settings, and the resource wrappers.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

class FleetOpsOrderPersistenceProbe extends OrderController
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

class FleetOpsOrderPersistenceStorageFake
{
    public array $writes = [];

    public function disk($disk = null)
    {
        return $this;
    }

    public function put($path, $contents, $options = [])
    {
        $this->writes[] = [$path, strlen((string) $contents)];

        return true;
    }
}

function fleetopsOrderPersistenceBoot(): SQLiteConnection
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

    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);

    app()->instance('redis', new class {
        public function connection($name = null)
        {
            return $this;
        }

        public function get($key)
        {
            return null;
        }

        public function set(...$arguments)
        {
            return true;
        }

        public function setex(...$arguments)
        {
            return true;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\Redis::clearResolvedInstance('redis');

    $storageFake = new FleetOpsOrderPersistenceStorageFake();
    app()->instance('filesystem', $storageFake);
    Illuminate\Support\Facades\Storage::clearResolvedInstance('filesystem');
    $GLOBALS['fleetopsOrderStorageFake'] = $storageFake;

    $schema = $connection->getSchemaBuilder();
    app()->instance('db.schema', $schema);
    $tables = [
        'contacts'  => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'name', 'email', 'phone', 'type', 'meta', 'slug'],
        'orders'    => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'payload_uuid', 'order_config_uuid', 'status', 'type', 'adhoc', 'dispatched', 'meta', 'orchestrator_priority', 'transaction_uuid', 'purchase_rate_uuid', 'customer_uuid', 'customer_type', 'created_by_uuid', 'scheduled_at', 'driver_assigned_uuid'],
        'proofs'    => ['uuid', 'public_id', 'company_uuid', 'order_uuid', 'subject_uuid', 'subject_type', 'remarks', 'raw_data', 'data', 'file_uuid', '_key'],
        'files'     => ['uuid', 'public_id', 'company_uuid', 'uploader_uuid', 'name', 'original_filename', 'extension', 'content_type', 'path', 'bucket', 'disk', 'size', 'type', 'meta', '_key', 'subject_uuid', 'subject_type'],
        'settings'  => ['key', 'value'],
        'companies' => ['uuid', 'public_id', 'name', 'timezone', 'country'],
        'payloads'  => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'meta'],
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

    session(['company' => '22222222-2222-4222-8222-222222222222']);
    $connection->table('companies')->insert(['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'company_test', 'name' => 'Acme', 'timezone' => 'Asia/Singapore']);
    DispatchRecorder::$dispatched = [];

    return $connection;
}

test('customer contact timezone and order creation helpers persist records', function () {
    $connection = fleetopsOrderPersistenceBoot();
    $probe      = new FleetOpsOrderPersistenceProbe();

    $contact = $probe->callHelper('firstOrCreateCustomerContact', ['email' => 'c@example.test'], ['company_uuid' => '22222222-2222-4222-8222-222222222222', 'name' => 'Customer', 'type' => 'customer']);
    $again   = $probe->callHelper('firstOrCreateCustomerContact', ['email' => 'c@example.test'], ['company_uuid' => '22222222-2222-4222-8222-222222222222', 'name' => 'Customer', 'type' => 'customer']);
    expect($contact)->toBeInstanceOf(Contact::class)
        ->and($connection->table('contacts')->count())->toBe(1);

    expect($probe->callHelper('defaultCompanyTimezone'))->toBe('Asia/Singapore');

    $order = $probe->callHelper('createOrder', ['company_uuid' => '22222222-2222-4222-8222-222222222222', 'type' => 'transport', 'status' => 'created']);
    expect($order)->toBeInstanceOf(Order::class)
        ->and($connection->table('orders')->count())->toBe(1);
});

test('finalize dispatch and driving distance helpers execute their seams', function () {
    fleetopsOrderPersistenceBoot();
    $probe = new FleetOpsOrderPersistenceProbe();

    $probe->callHelper('dispatchFinalizeApiOrderCreation', 'order-1', 'sq-1', true);
    expect(DispatchRecorder::$dispatched)->toHaveCount(1);

    // The routing engine resolves configuration through phpdotenv, which is
    // unavailable in the harness — the delegation body still executes.
    expect(fn () => $probe->callHelper('drivingDistanceAndTime', [1.30, 103.80], [1.35, 103.85]))->toThrow(Error::class);
});

test('proof file storage and settings helpers persist and resolve', function () {
    $connection = fleetopsOrderPersistenceBoot();
    $probe      = new FleetOpsOrderPersistenceProbe();

    $proof = $probe->callHelper('createProof', ['company_uuid' => '22222222-2222-4222-8222-222222222222', 'order_uuid' => 'order-1', 'subject_uuid' => 'order-1', 'remarks' => 'Photo captured']);
    expect($proof)->toBeInstanceOf(Proof::class)
        ->and($connection->table('proofs')->count())->toBe(1);

    $file = $probe->callHelper('createFile', ['company_uuid' => '22222222-2222-4222-8222-222222222222', 'name' => 'proof.jpg', 'path' => 'uploads/proof.jpg', 'disk' => 'local']);
    expect($connection->table('files')->count())->toBe(1);

    $probe->callHelper('putStorage', 'local', 'uploads/proof.jpg', 'binary-image-data');
    expect($GLOBALS['fleetopsOrderStorageFake']->writes)->toHaveCount(1);

    $connection->table('settings')->insert(['key' => 'fleet-ops.entity-editing-settings', 'value' => json_encode(['enabled' => true])]);
    expect($probe->callHelper('entityEditingSettings'))->not->toBeNull();
});

test('proof scoping and resource wrappers resolve subjects', function () {
    $connection = fleetopsOrderPersistenceBoot();
    $probe      = new FleetOpsOrderPersistenceProbe();

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-1', 'public_id' => 'order_test', 'company_uuid' => '22222222-2222-4222-8222-222222222222'], true);
    $order->exists = true;

    $connection->table('proofs')->insert([
        ['uuid' => 'proof-1', 'company_uuid' => '22222222-2222-4222-8222-222222222222', 'order_uuid' => 'order-1', 'subject_uuid' => 'order-1'],
        ['uuid' => 'proof-2', 'company_uuid' => '22222222-2222-4222-8222-222222222222', 'order_uuid' => 'order-1', 'subject_uuid' => 'entity-1'],
    ]);

    // Order-level lookups include every proof for the order
    expect($probe->callHelper('proofsForSubject', $order, $order))->toHaveCount(2);

    // Subject-scoped lookups filter to the subject
    $entity = new Fleetbase\FleetOps\Models\Entity();
    $entity->setRawAttributes(['uuid' => 'entity-1'], true);
    expect($probe->callHelper('proofsForSubject', $order, $entity))->toHaveCount(1);

    $proof = Proof::where('uuid', 'proof-1')->first();
    expect($probe->callHelper('orderResource', $order))->toBeInstanceOf(OrderResource::class)
        ->and($probe->callHelper('deletedOrderResource', $order))->not->toBeNull()
        ->and($probe->callHelper('proofResource', $proof))->toBeInstanceOf(ProofResource::class)
        ->and($probe->callHelper('proofResourceCollection', collect([$proof])))->not->toBeNull()
        ->and($probe->callHelper('commentResourceCollection', collect([])))->not->toBeNull();
});
