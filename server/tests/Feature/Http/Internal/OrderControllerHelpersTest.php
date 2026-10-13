<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\Waypoint;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the real bodies of the internal OrderController's protected helper
 * methods against an in-memory SQLite fixture: customer type normalization,
 * bulk driver assignment, domain event dispatch, proof photo storage, proof
 * subject resolution, tracking number lookup, and schedule lookups.
 */
if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Internal\v1\event')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1; function event($event = null) { \FleetOpsInternalOrderHelpersRecorder::$events[] = $event; return $event; }');
}

class FleetOpsInternalOrderHelpersRecorder
{
    public static array $events = [];
}

class FleetOpsInternalOrderHelpersStorageFake
{
    public array $writes = [];

    public function disk($name = null): self
    {
        return $this;
    }

    public function put(string $path, $contents): bool
    {
        $this->writes[] = [$path, strlen((string) $contents)];

        return true;
    }
}

class FleetOpsInternalOrderHelpersProbe extends OrderController
{
    public function callProtected(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(OrderController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }

    public function normalizeCustomerTypeForTest(array &$input): void
    {
        $this->normalizeCustomerType($input);
    }
}

class FleetOpsInternalOrderHelpersOrderFake extends Order
{
    protected $guarded = [];
    public $exists     = true;
}

class FleetOpsInternalOrderHelpersProofFake extends Proof
{
    protected $guarded = [];
    public $exists     = true;
}

function fleetopsInternalOrderHelpersBoot(): SQLiteConnection
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
    app()->instance('db.schema', $connection->getSchemaBuilder());
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'orders'           => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'tracking_number_uuid', 'driver_assigned_uuid', 'status'],
        'contacts'         => ['uuid', 'public_id', 'company_uuid', 'name', 'type'],
        'vendors'          => ['uuid', 'public_id', 'company_uuid', 'name'],
        'drivers'          => ['uuid', 'public_id', 'company_uuid', 'user_uuid'],
        'users'            => ['uuid', 'public_id', 'company_uuid'],
        'payloads'         => ['uuid', 'public_id', 'company_uuid'],
        'waypoints'        => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid'],
        'places'           => ['uuid', 'public_id', 'company_uuid', 'name'],
        'proofs'           => ['uuid', 'public_id', 'company_uuid', 'order_uuid', 'subject_uuid', 'subject_type', 'file_uuid', 'remarks'],
        'files'            => ['uuid', 'public_id', 'company_uuid', 'uploader_uuid', 'name', 'original_filename', 'extension', 'content_type', 'path', 'bucket', 'type', 'size', 'key_uuid', 'key_type', 'subject_uuid', 'subject_type', 'disk'],
        'tracking_numbers' => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid'],
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
    FleetOpsInternalOrderHelpersRecorder::$events = [];

    return $connection;
}

test('normalize customer type resolves contacts and vendors to their model classes', function () {
    $connection = fleetopsInternalOrderHelpersBoot();
    $connection->table('contacts')->insert(['uuid' => 'contact-1', 'company_uuid' => 'company-1', 'name' => 'Ada']);
    $connection->table('vendors')->insert(['uuid' => 'vendor-1', 'company_uuid' => 'company-1', 'name' => 'Acme']);

    $probe = new FleetOpsInternalOrderHelpersProbe();

    $input = ['customer_uuid' => 'contact-1'];
    $probe->normalizeCustomerTypeForTest($input);
    expect($input['customer_uuid'])->toBe('contact-1')
        ->and($input['customer_type'] ?? null)->toBe('\\' . Fleetbase\FleetOps\Models\Contact::class);

    $vendorInput = ['customer' => ['uuid' => 'vendor-1']];
    $probe->normalizeCustomerTypeForTest($vendorInput);
    expect($vendorInput['customer_type'] ?? null)->toBe('\\' . Fleetbase\FleetOps\Models\Vendor::class);

    $unchanged = ['meta' => []];
    $probe->normalizeCustomerTypeForTest($unchanged);
    expect($unchanged)->toBe(['meta' => []]);
});

test('bulk assign driver validation enforces uuid contracts', function () {
    fleetopsInternalOrderHelpersBoot();
    $probe = new FleetOpsInternalOrderHelpersProbe();

    $valid = $probe->callProtected('validateBulkAssignDriverRequest', Request::create('/x', 'POST', [
        'ids'    => ['11111111-1111-4111-8111-111111111111'],
        'driver' => '22222222-2222-4222-8222-222222222222',
    ]));
    expect($valid['driver'])->toBe('22222222-2222-4222-8222-222222222222');
});

test('assign driver to orders updates the assignment column', function () {
    $connection = fleetopsInternalOrderHelpersBoot();
    $connection->table('orders')->insert([
        ['uuid' => 'order-1', 'company_uuid' => 'company-1'],
        ['uuid' => 'order-2', 'company_uuid' => 'company-1'],
    ]);
    $driver = new Driver();
    $driver->setRawAttributes(['uuid' => 'driver-1'], true);

    (new FleetOpsInternalOrderHelpersProbe())->callProtected('assignDriverToOrders', ['order-1', 'order-2'], $driver);

    expect($connection->table('orders')->where('driver_assigned_uuid', 'driver-1')->count())->toBe(2);
});

test('dispatch domain event helper emits and returns the event', function () {
    fleetopsInternalOrderHelpersBoot();
    $event = new stdClass();

    $returned = (new FleetOpsInternalOrderHelpersProbe())->callProtected('dispatchDomainEvent', $event);

    expect($returned)->toBe($event)
        ->and(FleetOpsInternalOrderHelpersRecorder::$events)->toBe([$event]);
});

test('store proof photo persists a decoded file record', function () {
    $connection = fleetopsInternalOrderHelpersBoot();
    session(['user' => 'user-1']);
    $storage = new FleetOpsInternalOrderHelpersStorageFake();
    app()->instance('filesystem', $storage);
    Illuminate\Support\Facades\Storage::clearResolvedInstance('filesystem');

    $proof = new FleetOpsInternalOrderHelpersProofFake();
    $proof->setRawAttributes(['uuid' => 'proof-1', 'public_id' => 'proof_test'], true);

    $file = (new FleetOpsInternalOrderHelpersProbe())->callProtected(
        'storeProofPhoto',
        $proof,
        base64_encode('fake-image-bytes'),
        'local',
        'uploads-bucket'
    );

    expect($file)->toBeInstanceOf(Fleetbase\Models\File::class)
        ->and($storage->writes)->toHaveCount(1)
        ->and($storage->writes[0][0])->toContain('proof_test.png')
        ->and($connection->table('files')->count())->toBe(1)
        ->and($connection->table('files')->value('bucket'))->toBe('uploads-bucket');
});

test('proof subject resolution returns the order waypoint or fallback lookups', function () {
    $connection = fleetopsInternalOrderHelpersBoot();
    $connection->table('waypoints')->insert(['uuid' => 'waypoint-1', 'public_id' => 'waypoint_test', 'payload_uuid' => 'payload-1', 'place_uuid' => 'place-1']);
    $connection->table('places')->insert(['uuid' => 'place-1', 'public_id' => 'place_test', 'name' => 'Depot']);

    $order = new FleetOpsInternalOrderHelpersOrderFake();
    $order->setRawAttributes(['uuid' => 'order-1', 'payload_uuid' => 'payload-1'], true);
    $order->setRelation('payload', null);

    $probe = new FleetOpsInternalOrderHelpersProbe();

    expect($probe->callProtected('resolveProofSubject', $order, null))->toBe($order);

    $waypoint = $probe->callProtected('resolveProofSubject', $order, 'waypoint_test');
    expect($waypoint)->toBeInstanceOf(Waypoint::class)
        ->and($waypoint->uuid)->toBe('waypoint-1');

    $byPlace = $probe->callProtected('findWaypointProofSubject', $order, 'place-1');
    expect($byPlace)->toBeInstanceOf(Waypoint::class);

    expect($probe->callProtected('findWaypointProofSubject', $order, 'missing'))->toBeNull();
});

test('proofs for subject scopes to the order and optional subject', function () {
    $connection = fleetopsInternalOrderHelpersBoot();
    $connection->table('proofs')->insert([
        ['uuid' => 'proof-1', 'company_uuid' => 'company-1', 'order_uuid' => 'order-1', 'subject_uuid' => 'order-1'],
        ['uuid' => 'proof-2', 'company_uuid' => 'company-1', 'order_uuid' => 'order-1', 'subject_uuid' => 'waypoint-1'],
    ]);

    $order = new FleetOpsInternalOrderHelpersOrderFake();
    $order->setRawAttributes(['uuid' => 'order-1'], true);
    $waypoint = new Waypoint();
    $waypoint->setRawAttributes(['uuid' => 'waypoint-1'], true);

    $probe = new FleetOpsInternalOrderHelpersProbe();

    expect($probe->callProtected('proofsForSubject', $order, $order)->count())->toBe(2)
        ->and($probe->callProtected('proofsForSubject', $order, $waypoint)->count())->toBe(1);
});

test('tracking number and schedule lookups execute against the database', function () {
    $connection = fleetopsInternalOrderHelpersBoot();
    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-1', 'tracking_number' => 'FLB-123456']);
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_test', 'company_uuid' => 'company-1', 'tracking_number_uuid' => 'tn-1']);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_test', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);

    $probe = new FleetOpsInternalOrderHelpersProbe();

    $order = $probe->callProtected('findOrderByTrackingNumber', 'FLB-123456');
    expect($order)->toBeInstanceOf(Order::class)
        ->and($order->uuid)->toBe('order-1')
        ->and($probe->callProtected('findOrderByTrackingNumber', 'MISSING'))->toBeNull();

    expect($probe->callProtected('findDriverForSchedule', 'driver-1'))->toBeInstanceOf(Driver::class)
        ->and($probe->callProtected('findDriverForSchedule', 'driver_test'))->toBeInstanceOf(Driver::class)
        ->and($probe->callProtected('findDriverForSchedule', 'missing'))->toBeNull();
});
