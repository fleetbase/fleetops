<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\Models\session')) {
    eval('namespace Fleetbase\Models; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } public function missing($k) { return \session($k) === null; } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\\Observers\\event')) {
    eval('namespace Fleetbase\\Observers; function event($event = null, $payload = []) { return []; }');
}

use Fleetbase\FleetOps\Http\Controllers\Api\v1\CustomerController;
use Fleetbase\FleetOps\Http\Requests\CreateCustomerRequest;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\CustomerAuth;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the customer API controller's protected helper seams against
 * SQLite: identity checks, user and contact lookups and creation, password
 * hashing, verification-code queries, file lookups, place helpers, sanctum
 * token issuance and revocation, order and place request queries, order
 * config and record helpers, device registration, resource wrappers, and
 * phone normalization.
 */
if (!Request::hasMacro('getController')) {
    Request::macro('getController', fn () => new CustomerController());
}

if (!Request::hasMacro('or')) {
    Request::macro('or', function (array $params = [], $default = null) {
        foreach ($params as $param) {
            if ($this->has($param)) {
                return $this->input($param);
            }
        }

        return $default;
    });
}

if (!Request::hasMacro('isArray')) {
    Request::macro('isArray', fn (string $key) => is_array($this->input($key)));
}

class FleetOpsCustomerControllerProbe extends CustomerController
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

function fleetopsCustomerHelperBoot(): SQLiteConnection
{
    $pdo      = new PDO('sqlite::memory:');
    $wkbPoint = fn (float $lng, float $lat) => pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $lng) . pack('d', $lat);
    $pdo->sqliteCreateFunction('ST_PointFromText', function ($wkt, $srid = 0, $axisOrder = null) use ($wkbPoint) {
        if (is_string($wkt) && sscanf($wkt, 'POINT(%f %f)', $lng, $lat) === 2) {
            return $wkbPoint($lng, $lat);
        }

        return $wkt;
    });
    $pdo->sqliteCreateFunction('ST_GeomFromText', function ($wkt, $srid = 0, $axisOrder = null) use ($wkbPoint) {
        if (is_string($wkt) && sscanf($wkt, 'POINT(%f %f)', $lng, $lat) === 2) {
            return $wkbPoint($lng, $lat);
        }

        return $wkt;
    });
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    if (!Illuminate\Support\Str::hasMacro('humanize')) {
        Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
    }
    // Model uuid hooks bind to the dispatcher present at class boot, so keep
    // one dispatcher instance for the whole file run
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }
    $barcodeFake = new class {
        public function __call($method, $arguments)
        {
            return 'barcode';
        }
    };
    app()->instance('DNS2D', $barcodeFake);
    app()->instance('DNS1D', $barcodeFake);
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());
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

    app()->instance('hash', new class {
        public function check($value, $hash)
        {
            return $value === 'secret' && $hash === 'hashed-secret';
        }

        public function make($value, array $options = [])
        {
            return 'hashed-' . $value;
        }

        public function needsRehash($hash, array $options = [])
        {
            return false;
        }

        public function driver($driver = null)
        {
            return $this;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\Hash::clearResolvedInstance('hash');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'users'                  => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'password', 'type', 'status', 'timezone', 'slug', 'username', 'avatar_uuid', 'meta', '_key'],
        'contacts'               => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'name', 'email', 'phone', 'type', 'title', 'meta', 'photo_uuid', 'place_uuid', 'internal_id', 'slug', '_key'],
        'places'                 => ['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'owner_type', 'name', 'street1', 'location', 'type', '_key', '_import_id'],
        'verification_codes'     => ['uuid', 'public_id', 'subject_uuid', 'subject_type', 'code', 'for', 'expires_at', 'meta', 'status', '_key'],
        'files'                  => ['uuid', 'public_id', 'company_uuid', 'uploader_uuid', 'name', 'original_filename', 'extension', 'content_type', 'path', 'bucket', 'disk', 'folder', 'meta', 'type', 'size', 'subject_uuid', 'subject_type', '_key'],
        'orders'                 => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'payload_uuid', 'customer_uuid', 'customer_type', 'tracking_number_uuid', 'order_config_uuid', 'status', 'type', 'meta', 'dispatched', 'started', 'adhoc', 'pod_required', 'orchestrator_priority', 'scheduled_at', '_key'],
        'payloads'               => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'current_waypoint_uuid', 'type', 'meta', '_key'],
        'order_configs'          => ['uuid', 'public_id', 'company_uuid', 'name', 'key', 'namespace', 'flow', 'entities', 'meta', 'version', 'core_service', 'status', 'type', '_key'],
        'personal_access_tokens' => ['tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'last_used_at', 'expires_at'],
        'user_devices'           => ['uuid', 'public_id', 'user_uuid', 'token', 'platform', 'status', 'meta', '_key'],
        'service_quotes'         => ['uuid', 'public_id', 'request_id', 'company_uuid', 'payload_uuid', 'service_rate_uuid', 'amount', 'currency', 'meta', 'expired_at', '_key'],
        'waypoints'              => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'customer_uuid', 'customer_type', 'order', 'type'],
        'entities'               => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'destination_uuid', 'name', 'type'],
        'tracking_numbers'       => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type', 'region', 'qr_code', 'barcode', 'status_uuid', 'location', '_key'],
        'companies'              => ['uuid', 'public_id', 'name', 'country'],
        'tracking_statuses'      => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'proof_uuid', 'status', 'details', 'location', 'code', 'complete', '_key'],
        'directives'             => ['uuid', 'public_id', 'company_uuid', 'permission_uuid', 'key', 'rules', 'subject_type'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                if (in_array($column, ['dispatched', 'started', 'adhoc', 'pod_required', 'core_service', 'orchestrator_priority'], true)) {
                    $blueprint->integer($column)->nullable();
                    continue;
                }
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    app()->instance('db.schema', $schema);
    Illuminate\Support\Facades\Schema::clearResolvedInstance('db.schema');

    session(['company' => 'company-1']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme', 'country' => 'SG']);

    return $connection;
}

function fleetopsCustomerHelperRequest(string $uri, array $input = []): Request
{
    $request = Request::create('/' . $uri, 'GET', $input);
    $store   = app('session.store');
    $store->put('company', 'company-1');
    $request->setLaravelSession($store);
    $request->setRouteResolver(fn () => new class {
        public function getAction($key = null)
        {
            return CustomerController::class . '@orders';
        }

        public function getActionMethod()
        {
            return 'orders';
        }

        public function uri()
        {
            return 'v1/customers';
        }

        public function getName()
        {
            return 'api.v1.customers.query';
        }

        public function parameters()
        {
            return [];
        }
    });

    return $request;
}

test('identity checks session context and user lookups resolve', function () {
    $connection = fleetopsCustomerHelperBoot();
    $connection->table('users')->insert([
        'uuid'  => 'user-1', 'company_uuid' => 'company-1', 'name' => 'Casey',
        'email' => 'casey@example.com', 'phone' => '+6591234567', 'password' => 'hashed-secret', 'type' => 'customer',
    ]);

    $probe = new FleetOpsCustomerControllerProbe();

    expect($probe->callHelper('isEmail', 'casey@example.com'))->toBeTrue()
        ->and($probe->callHelper('isEmail', 'nope'))->toBeFalse()
        ->and($probe->callHelper('isPublicId', 'contact_abc1234'))->toBeTrue()
        ->and($probe->callHelper('isBase64String', base64_encode('hello world')))->toBeTrue()
        ->and($probe->callHelper('sessionCompany'))->toBe('company-1');

    expect($probe->callHelper('findActiveUserByIdentity', 'casey@example.com', 'email')?->uuid)->toBe('user-1')
        ->and($probe->callHelper('findUserByIdentity', '+6591234567', 'phone')?->uuid)->toBe('user-1')
        ->and($probe->callHelper('findUserForLogin', 'casey@example.com')?->uuid)->toBe('user-1')
        ->and($probe->callHelper('findUserForVerification', '+6591234567')?->uuid)->toBe('user-1')
        ->and($probe->callHelper('findUserByUuid', 'user-1')?->uuid)->toBe('user-1')
        ->and($probe->callHelper('passwordMatches', 'secret', 'hashed-secret'))->toBeTrue()
        ->and($probe->callHelper('passwordMatches', 'wrong', 'hashed-secret'))->toBeFalse();

    $created = $probe->callHelper('createUser', ['name' => 'New User', 'email' => 'new@example.com', 'type' => 'customer']);
    expect($created)->toBeInstanceOf(User::class)
        ->and($connection->table('users')->where('email', 'new@example.com')->count())->toBe(1);

    expect($probe->callHelper('updateUserByUuid', 'user-1', ['timezone' => 'Asia/Singapore']))->toBe(1)
        ->and($connection->table('users')->where('uuid', 'user-1')->value('timezone'))->toBe('Asia/Singapore');
});

test('verification codes files contacts and customer auth resolve', function () {
    $connection = fleetopsCustomerHelperBoot();
    $connection->table('verification_codes')->insert(['uuid' => 'vc-1', 'subject_uuid' => 'user-1', 'subject_type' => 'user', 'code' => '123456', 'for' => 'customer_verification']);
    $connection->table('files')->insert(['uuid' => 'file-1', 'public_id' => 'file_custhelp1', 'company_uuid' => 'company-1', 'name' => 'photo.png']);
    $connection->table('contacts')->insert(['uuid' => 'contact-1', 'public_id' => 'contact_custhelp1', 'company_uuid' => 'company-1', 'name' => 'Casey', 'type' => 'customer']);

    $probe = new FleetOpsCustomerControllerProbe();

    expect($probe->callHelper('verificationCodeExists', ['code' => '123456', 'for' => 'customer_verification']))->toBeTrue()
        ->and($probe->callHelper('findVerificationCode', ['code' => '123456'])?->uuid)->toBe('vc-1')
        ->and($probe->callHelper('findVerificationCode', ['code' => '999999']))->toBeNull()
        ->and($probe->callHelper('findFileByPublicId', 'file_custhelp1')?->uuid)->toBe('file-1');

    expect($probe->callHelper('findCustomerContact', ['uuid' => 'contact-1'])?->name)->toBe('Casey')
        ->and($probe->callHelper('createContact', ['name' => 'Fresh Contact', 'type' => 'customer', 'company_uuid' => 'company-1']))->toBeInstanceOf(Contact::class);

    $firstOrCreate = $probe->callHelper('firstOrCreateCustomerContact', ['uuid' => 'contact-1'], ['name' => 'Ignored']);
    expect($firstOrCreate->uuid)->toBe('contact-1');

    // Customer auth binding round-trip
    expect($probe->callHelper('currentCustomer'))->toBeNull();
    CustomerAuth::setCurrent($firstOrCreate);
    expect($probe->callHelper('currentCustomer')?->uuid)->toBe('contact-1');

    // Resource wrappers accept the resolved records
    expect($probe->callHelper('customerResource', $firstOrCreate))->not->toBeNull();
});

test('place order and device helpers create and query records', function () {
    $connection = fleetopsCustomerHelperBoot();
    $connection->table('places')->insert(['uuid' => 'place-1', 'public_id' => 'place_custhelp1', 'company_uuid' => 'company-1', 'name' => 'Depot']);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'public_id' => 'payload_custhelp1', 'company_uuid' => 'company-1']);
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_custhelp1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'status' => 'created']);
    $connection->table('order_configs')->insert(['uuid' => 'config-1', 'public_id' => 'order_config_custhelp', 'company_uuid' => 'company-1', 'name' => 'Transport', 'key' => 'transport', 'namespace' => 'system:order-config:transport', 'core_service' => 1, 'version' => '0.0.1', 'flow' => '{}']);

    $probe = new FleetOpsCustomerControllerProbe();

    expect($probe->callHelper('findPlaceByPublicId', 'place_custhelp1', 'company-1')?->uuid)->toBe('place-1')
        ->and($probe->callHelper('createPlace', ['name' => 'New Stop', 'company_uuid' => 'company-1']))->toBeInstanceOf(Place::class)
        ->and($probe->callHelper('getUuid', 'places', ['public_id' => 'place_custhelp1']))->not->toBeNull()
        ->and($probe->callHelper('getModelClassName', 'orders'))->toBe('\Fleetbase\FleetOps\Models\Order')
        ->and($probe->callHelper('newPayload'))->toBeInstanceOf(Payload::class);

    $order = $probe->callHelper('createOrderRecord', ['company_uuid' => 'company-1', 'status' => 'created', 'type' => 'transport']);
    expect($order)->toBeInstanceOf(Order::class);

    expect($probe->callHelper('findOrderOrFail', 'order_custhelp1')?->uuid)->toBe('order-1');

    $orders = $probe->callHelper('queryOrders', fleetopsCustomerHelperRequest('v1/orders'), function ($query) {
        $query->where('company_uuid', 'company-1');
    });
    expect($orders->count())->toBeGreaterThanOrEqual(1);

    $places = $probe->callHelper('queryPlaces', fleetopsCustomerHelperRequest('v1/places'), function ($query) {
        $query->where('company_uuid', 'company-1');
    });
    expect($places->count())->toBeGreaterThanOrEqual(1);

    $device = $probe->callHelper('firstOrCreateDevice', ['token' => 'device-token-1'], ['platform' => 'ios', 'status' => 'active']);
    expect($device)->not->toBeNull()
        ->and($connection->table('user_devices')->where('token', 'device-token-1')->count())->toBe(1);

    expect($probe->callHelper('orderResource', $order))->not->toBeNull()
        ->and($probe->callHelper('orderResourceCollection', collect([$order])))->not->toBeNull()
        ->and($probe->callHelper('placeResourceCollection', collect([])))->not->toBeNull();
});

test('sanctum tokens issue resolve and revoke for customer users', function () {
    $connection = fleetopsCustomerHelperBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'name' => 'Casey', 'email' => 'casey@example.com', 'type' => 'customer']);
    $connection->table('contacts')->insert(['uuid' => 'contact-1', 'public_id' => 'contact_custtok1', 'company_uuid' => 'company-1', 'name' => 'Casey', 'type' => 'customer']);

    $probe   = new FleetOpsCustomerControllerProbe();
    $user    = User::where('uuid', 'user-1')->first();
    $contact = Contact::where('uuid', 'contact-1')->first();

    $token = $probe->callHelper('createCustomerToken', $user, $contact);
    expect($token->plainTextToken)->toBeString()
        ->and($connection->table('personal_access_tokens')->count())->toBe(1);

    $found = $probe->callHelper('findAccessToken', $token->plainTextToken);
    expect($found?->name)->toBe('contact-1');

    $probe->callHelper('deleteUserTokens', $user);
    expect($connection->table('personal_access_tokens')->count())->toBe(0);

    expect(CustomerController::phone('6591234567'))->toBe('+6591234567')
        ->and(CustomerController::phone('  '))->toBe('');
});

test('create customer rejects invalid codes and backfills stub users', function () {
    $connection = fleetopsCustomerHelperBoot();
    $controller = new CustomerController();

    // Invalid verification code
    $invalid = $controller->create(CreateCustomerRequest::create('/v1/customers', 'POST', ['code' => '999999', 'identity' => 'stub@example.com']));
    expect($invalid->getData(true)['error'])->toContain('Invalid verification code');

    // Password-less stub user matched by email identity gets backfilled
    $connection->table('users')->insert(['uuid' => 'user-stub', 'company_uuid' => 'company-1', 'email' => 'stub@example.com', 'status' => 'active']);
    $connection->table('verification_codes')->insert(['uuid' => 'vc-stub', 'code' => '424242', 'for' => 'fleetops_create_customer', 'meta' => json_encode(['identity' => 'stub@example.com']), 'status' => 'active']);
    $created = $controller->create(CreateCustomerRequest::create('/v1/customers', 'POST', [
        'code' => '424242', 'identity' => 'stub@example.com', 'name' => 'Stubbed Customer', 'phone' => '+6591234567', 'password' => 'secret',
    ]));
    expect($connection->table('users')->where('uuid', 'user-stub')->value('name'))->toBe('Stubbed Customer')
        ->and($connection->table('users')->where('uuid', 'user-stub')->value('phone'))->toBe('+6591234567')
        ->and($connection->table('contacts')->where('user_uuid', 'user-stub')->count())->toBe(1);
});

test('create customer resolves existing phone users without overwriting credentials', function () {
    $connection = fleetopsCustomerHelperBoot();
    $controller = new CustomerController();

    $connection->table('users')->insert(['uuid' => 'user-phone', 'company_uuid' => 'company-1', 'phone' => '+6598765432', 'password' => 'already-hashed', 'name' => 'Existing Phone User', 'status' => 'active', 'type' => 'customer']);
    $connection->table('verification_codes')->insert(['uuid' => 'vc-phone', 'code' => '565656', 'for' => 'fleetops_create_customer', 'meta' => json_encode(['identity' => '+6598765432']), 'status' => 'active']);

    $created = $controller->create(CreateCustomerRequest::create('/v1/customers', 'POST', [
        'code' => '565656', 'identity' => '+6598765432', 'name' => 'New Name',
    ]));

    // Existing credentialed user keeps password/name, only a contact attaches
    expect($connection->table('users')->where('uuid', 'user-phone')->value('password'))->toBe('already-hashed')
        ->and($connection->table('contacts')->where('user_uuid', 'user-phone')->count())->toBe(1);
});

test('verify code delegates creation guards sessions and unknown identities', function () {
    $connection = fleetopsCustomerHelperBoot();
    $controller = new CustomerController();

    // Unknown identities cannot verify
    $unknown = $controller->verifyCode(Request::create('/v1/customers/verify-code', 'POST', [
        'identity' => 'ghost@example.com',
        'code'     => '111111',
    ]));
    expect($unknown->getData(true)['error'] ?? '')->toContain('Unable to verify');

    // The create-customer intent delegates into the full creation flow
    $connection->table('verification_codes')->insert(['uuid' => 'vc-del', 'code' => '777777', 'for' => 'fleetops_create_customer', 'meta' => json_encode(['identity' => 'delegate@example.com']), 'status' => 'active']);
    $delegated = $controller->verifyCode(Request::create('/v1/customers/verify-code', 'POST', [
        'identity' => 'delegate@example.com',
        'code'     => '777777',
        'for'      => 'fleetops_create_customer',
        'name'     => 'Delegated Customer',
        'password' => 'secret',
    ]));
    expect($delegated)->not->toBeNull()
        ->and($connection->table('contacts')->where('email', 'delegate@example.com')->count())->toBe(1);

    // Without a session company the create flow rejects with a 500
    session(['company' => null]);
    $connection->table('verification_codes')->insert(['uuid' => 'vc-nocompany', 'code' => '888888', 'for' => 'fleetops_create_customer', 'meta' => json_encode(['identity' => 'nocompany@example.com']), 'status' => 'active']);
    $noCompany = $controller->create(CreateCustomerRequest::create('/v1/customers', 'POST', [
        'identity' => 'nocompany@example.com',
        'code'     => '888888',
        'name'     => 'No Company',
    ]));
    expect($noCompany->getStatusCode())->toBe(500);
    session(['company' => 'company-1']);
});
