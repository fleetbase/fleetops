<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Trailer;
use Fleetbase\FleetOps\Providers\FleetOpsServiceProvider;
use Fleetbase\FleetOps\Support\SocketChannels;
use Fleetbase\Models\User;
use Fleetbase\Support\SocketCluster\ChannelAuthorizer;
use Fleetbase\Support\SocketCluster\ChannelDecision;
use Fleetbase\Support\SocketCluster\SocketChannelRegistry;
use Fleetbase\Support\SocketCluster\SocketPrincipal;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the FleetOps realtime channel resolvers against SQLite through the core
 * ChannelAuthorizer: company scoping for console users and API credentials across two
 * companies on every prefix, the narrow driver and customer rules, the denied public
 * tracking prefix, the driver socket principal, and the service provider wiring.
 */
const SOCKET_CHANNELS_TABLES = [
    'orders'             => 'order',
    'drivers'            => 'driver',
    'vehicles'           => 'vehicle',
    'assets'             => 'trailer',
    'devices'            => 'device',
    'waypoints'          => 'waypoint',
    'entities'           => 'entity',
    'places'             => 'place',
    'contacts'           => 'contact',
    'vendors'            => 'vendor',
    'integrated_vendors' => 'integrated_vendor',
    'fleets'             => 'fleet',
    'zones'              => 'zone',
    'service_areas'      => 'service_area',
    'service_rates'      => 'service_rate',
    'service_quotes'     => 'service_quote',
    'purchase_rates'     => 'purchase_rate',
    'tracking_statuses'  => 'tracking_status',
    'tracking_numbers'   => 'tracking_number',
    'payloads'           => 'payload',
    'positions'          => 'position',
    'companies'          => 'company',
    'users'              => 'user',
];

const SOCKET_CHANNELS_COLUMNS = [
    'uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'driver_assigned_uuid', 'vehicle_assigned_uuid',
    'payload_uuid', 'vehicle_uuid', 'user_uuid', 'attachable_uuid', 'attachable_type', 'subject_uuid', 'subject_type',
    'status', 'name', 'email', 'type', 'asset_class', 'started', 'started_at', 'place_uuid', 'service_area_uuid', '_key',
];

function socketChannelsBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection, 'sandbox' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    $schema = $connection->getSchemaBuilder();
    foreach (array_keys(SOCKET_CHANNELS_TABLES) as $table) {
        $schema->create($table, function ($blueprint) use ($table) {
            $blueprint->increments('id');
            foreach (SOCKET_CHANNELS_COLUMNS as $column) {
                // Positions have no public_id, as in the real schema.
                if ($table === 'positions' && $column === 'public_id') {
                    continue;
                }
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    // One record of every kind in each of two companies.
    foreach (['a', 'b'] as $company) {
        $connection->table('companies')->insert(['uuid' => "company-{$company}", 'public_id' => "company_{$company}"]);
        $connection->table('users')->insert(['uuid' => "drivers-user-{$company}", 'public_id' => "user_drivers_{$company}", 'company_uuid' => "company-{$company}"]);
        foreach (SOCKET_CHANNELS_TABLES as $table => $name) {
            if (in_array($table, ['companies', 'users'], true)) {
                continue;
            }

            $row = ['uuid' => "{$table}-{$company}", 'company_uuid' => "company-{$company}"];
            if ($table !== 'positions') {
                $row['public_id'] = "{$name}_{$company}";
            }
            if ($table === 'drivers') {
                $row['user_uuid'] = "drivers-user-{$company}";
            }
            if ($table === 'assets') {
                $row['asset_class'] = Trailer::ASSET_CLASS;
            }
            $connection->table($table)->insert($row);
        }
    }

    // Company A's operation: drivers, their vehicles, customers and orders.
    $connection->table('users')->insert([
        ['uuid' => 'user-driver-a1', 'public_id' => 'user_drivera1', 'company_uuid' => 'company-a'],
        ['uuid' => 'user-driver-a2', 'public_id' => 'user_drivera2', 'company_uuid' => 'company-a'],
        ['uuid' => 'user-driver-a3', 'public_id' => 'user_drivera3', 'company_uuid' => 'company-a'],
        ['uuid' => 'user-office-a', 'public_id' => 'user_officea', 'company_uuid' => 'company-a'],
    ]);
    $connection->table('drivers')->insert([
        ['uuid' => 'driver-a1', 'public_id' => 'driver_a1', 'company_uuid' => 'company-a', 'user_uuid' => 'user-driver-a1', 'vehicle_uuid' => 'vehicle-a1'],
        ['uuid' => 'driver-a2', 'public_id' => 'driver_a2', 'company_uuid' => 'company-a', 'user_uuid' => 'user-driver-a2', 'vehicle_uuid' => 'vehicle-a2'],
        ['uuid' => 'driver-a3', 'public_id' => 'driver_a3', 'company_uuid' => 'company-a', 'user_uuid' => 'user-driver-a3', 'vehicle_uuid' => 'vehicle-a3'],
    ]);
    $connection->table('vehicles')->insert([
        ['uuid' => 'vehicle-a1', 'public_id' => 'vehicle_a1', 'company_uuid' => 'company-a'],
        ['uuid' => 'vehicle-a2', 'public_id' => 'vehicle_a2', 'company_uuid' => 'company-a'],
        ['uuid' => 'vehicle-a3', 'public_id' => 'vehicle_a3', 'company_uuid' => 'company-a'],
    ]);
    $connection->table('contacts')->insert([
        ['uuid' => 'contact-a1', 'public_id' => 'contact_a1', 'company_uuid' => 'company-a', 'type' => 'customer'],
        ['uuid' => 'contact-a2', 'public_id' => 'contact_a2', 'company_uuid' => 'company-a', 'type' => 'customer'],
    ]);
    $connection->table('orders')->insert([
        // driver-a1 drives contact-a1's active order in vehicle-a1
        ['uuid' => 'order-a1', 'public_id' => 'order_a1', 'company_uuid' => 'company-a', 'customer_uuid' => 'contact-a1', 'driver_assigned_uuid' => 'driver-a1', 'vehicle_assigned_uuid' => 'vehicle-a1', 'status' => 'started'],
        // driver-a2's order for contact-a2, on which driver-a1 carries an entity
        ['uuid' => 'order-a2', 'public_id' => 'order_a2', 'company_uuid' => 'company-a', 'customer_uuid' => 'contact-a2', 'driver_assigned_uuid' => 'driver-a2', 'vehicle_assigned_uuid' => 'vehicle-a2', 'status' => 'dispatched', 'payload_uuid' => 'payload-a2'],
        // contact-a1's completed order with driver-a2
        ['uuid' => 'order-a3', 'public_id' => 'order_a3', 'company_uuid' => 'company-a', 'customer_uuid' => 'contact-a1', 'driver_assigned_uuid' => 'driver-a2', 'vehicle_assigned_uuid' => 'vehicle-a2', 'status' => 'completed'],
        // driver-a2's order with nothing to do with driver-a1
        ['uuid' => 'order-a4', 'public_id' => 'order_a4', 'company_uuid' => 'company-a', 'customer_uuid' => 'contact-a2', 'driver_assigned_uuid' => 'driver-a2', 'status' => 'started', 'payload_uuid' => 'payload-a4'],
        // contact-a1's active order with driver-a3 and no vehicle assigned to the order
        ['uuid' => 'order-a5', 'public_id' => 'order_a5', 'company_uuid' => 'company-a', 'customer_uuid' => 'contact-a1', 'driver_assigned_uuid' => 'driver-a3', 'status' => 'created'],
    ]);
    $connection->table('entities')->insert([
        ['uuid' => 'entity-a2', 'public_id' => 'entity_a2', 'company_uuid' => 'company-a', 'payload_uuid' => 'payload-a2', 'driver_assigned_uuid' => 'driver-a1'],
        ['uuid' => 'entity-a4', 'public_id' => 'entity_a4', 'company_uuid' => 'company-a', 'payload_uuid' => 'payload-a4', 'driver_assigned_uuid' => 'driver-a2'],
    ]);
    $connection->table('devices')->insert([
        ['uuid' => 'device-a1', 'public_id' => 'device_a1', 'company_uuid' => 'company-a', 'attachable_uuid' => 'vehicle-a1'],
        ['uuid' => 'device-a2', 'public_id' => 'device_a2', 'company_uuid' => 'company-a', 'attachable_uuid' => 'vehicle-a2'],
        ['uuid' => 'device-loose', 'public_id' => 'device_loose', 'company_uuid' => 'company-a', 'attachable_uuid' => null],
    ]);
    $connection->table('positions')->insert([
        ['uuid' => 'position-own', 'company_uuid' => 'company-a', 'subject_uuid' => 'driver-a1'],
        ['uuid' => 'position-vehicle', 'company_uuid' => 'company-a', 'subject_uuid' => 'vehicle-a1'],
        ['uuid' => 'position-other', 'company_uuid' => 'company-a', 'subject_uuid' => 'driver-a2'],
    ]);

    $registry = new SocketChannelRegistry();
    SocketChannels::register($registry);
    app()->instance(SocketChannelRegistry::class, $registry);
    session(['company' => null]);

    return $connection;
}

function socketChannelsPrincipal(string $kind, string $sub, array $claims = []): SocketPrincipal
{
    return SocketPrincipal::fromClaims(array_merge([
        'kind' => $kind,
        'sub'  => $sub,
        'cid'  => 'company-a',
        'cpid' => 'company_a',
        'env'  => 'live',
        'ids'  => [$sub],
    ], $claims));
}

function socketChannelsDecide(SocketPrincipal $principal, string $channel): ChannelDecision
{
    return app(ChannelAuthorizer::class)->authorize($principal, $channel);
}

function socketChannelsAllows(SocketPrincipal $principal, string $channel): bool
{
    $decision = socketChannelsDecide($principal, $channel);

    // A resolver error would also deny: make sure every denial here is a real decision.
    expect($decision->reason)->not->toBe('resolver_error');

    return $decision->allow;
}

function socketChannelsDriver(): SocketPrincipal
{
    return socketChannelsPrincipal('driver', 'driver-a1', ['ids' => ['driver-a1', 'driver_a1', 'user-driver-a1', 'user_drivera1']]);
}

function socketChannelsCustomer(): SocketPrincipal
{
    return socketChannelsPrincipal('customer', 'contact-a1', ['ids' => ['contact-a1', 'contact_a1']]);
}

test('every fleetops prefix is registered and tracking channels are denied to everyone', function () {
    socketChannelsBoot();
    $registry = app(SocketChannelRegistry::class);

    foreach ([...array_keys(SocketChannels::MODEL_PREFIXES), ...array_keys(SocketChannels::MORPH_PREFIXES), 'position', 'tracking'] as $prefix) {
        expect($registry->resolve($prefix))->not->toBeNull();
    }

    $user = socketChannelsPrincipal('user', 'user-office-a', ['ids' => ['user-office-a']]);
    expect(socketChannelsAllows($user, 'tracking.abcdefghijklmnopqrstuvwxyz'))->toBeFalse()
        ->and(socketChannelsAllows(socketChannelsDriver(), 'tracking.abcdefghijklmnopqrstuvwxyz'))->toBeFalse()
        ->and(socketChannelsAllows(socketChannelsCustomer(), 'tracking.abcdefghijklmnopqrstuvwxyz'))->toBeFalse();
});

test('console users and api credentials reach every record of their own company and nothing of another', function () {
    socketChannelsBoot();
    $user = socketChannelsPrincipal('user', 'user-office-a', ['ids' => ['user-office-a', 'user_officea']]);
    $api  = socketChannelsPrincipal('api', 'api-credential-a', ['ids' => ['api-credential-a']]);

    $prefixes = [
        ...array_map(fn ($class) => (new $class())->getTable(), SocketChannels::MODEL_PREFIXES),
        'customer'    => 'contacts',
        'vendor'      => 'vendors',
        'facilitator' => 'integrated_vendors',
    ];

    foreach ($prefixes as $prefix => $table) {
        $name = SOCKET_CHANNELS_TABLES[$table];
        foreach ([$user, $api] as $principal) {
            expect(socketChannelsAllows($principal, "{$prefix}.{$table}-a"))->toBeTrue("{$principal->kind} {$prefix} by uuid")
                ->and(socketChannelsAllows($principal, "{$prefix}.{$name}_a"))->toBeTrue("{$principal->kind} {$prefix} by public id")
                ->and(socketChannelsAllows($principal, "{$prefix}.{$table}-b"))->toBeFalse("{$principal->kind} {$prefix} of another company")
                ->and(socketChannelsAllows($principal, "{$prefix}.missing-record"))->toBeFalse("{$principal->kind} {$prefix} unknown id");
        }
    }

    // Morph prefixes try each kind of record in turn.
    expect(socketChannelsAllows($user, 'customer.vendors-a'))->toBeTrue()
        ->and(socketChannelsAllows($user, 'facilitator.vendors-a'))->toBeTrue()
        ->and(socketChannelsAllows($user, 'facilitator.contacts-a'))->toBeTrue()
        ->and(socketChannelsAllows($user, 'vendor.integrated_vendors-b'))->toBeFalse();

    // Positions have no public id and are found by uuid only.
    expect(socketChannelsAllows($user, 'position.positions-a'))->toBeTrue()
        ->and(socketChannelsAllows($api, 'position.positions-b'))->toBeFalse();

    // Sandbox principals look records up on the sandbox connection.
    $sandbox = socketChannelsPrincipal('user', 'user-office-a', ['env' => 'test']);
    expect(socketChannelsAllows($sandbox, 'customer.contacts-a'))->toBeTrue()
        ->and(SocketChannels::connection($sandbox))->toBe('sandbox')
        ->and(SocketChannels::connection($user))->toBeNull();

    // A company-scoped principal without a company sees nothing.
    $orphan = SocketPrincipal::fromClaims(['kind' => 'user', 'sub' => 'user-orphan', 'ids' => ['user-orphan']]);
    expect(socketChannelsAllows($orphan, 'customer.contacts-a'))->toBeFalse()
        ->and(socketChannelsAllows($orphan, 'position.positions-a'))->toBeFalse();
});

test('a driver reaches only their own driver record, vehicle, devices, positions and assigned orders', function () {
    socketChannelsBoot();
    $driver = socketChannelsDriver();

    // Orders: assigned directly, or through an entity on the payload; never someone else's.
    expect(socketChannelsAllows($driver, 'order.order-a1'))->toBeTrue()
        ->and(socketChannelsAllows($driver, 'order.order_a1'))->toBeTrue()
        ->and(socketChannelsAllows($driver, 'order.order-a2'))->toBeTrue()
        ->and(socketChannelsAllows($driver, 'order.order-a4'))->toBeFalse()
        ->and(socketChannelsAllows($driver, 'order.order-a3'))->toBeFalse()
        ->and(socketChannelsAllows($driver, 'order.orders-b'))->toBeFalse();

    // Their own driver record, under either channel the order events use.
    expect(socketChannelsAllows($driver, 'driver.driver-a1'))->toBeTrue()
        ->and(socketChannelsAllows($driver, 'driverAssigned.driver-a1'))->toBeTrue()
        ->and(socketChannelsAllows($driver, 'driverAssigned.driver_a1'))->toBeTrue()
        ->and(socketChannelsAllows($driver, 'driverAssigned.driver-a2'))->toBeFalse()
        ->and(socketChannelsAllows($driver, 'driver.driver-a2'))->toBeFalse();

    // Their current vehicle, its devices and positions; not another driver's.
    expect(socketChannelsAllows($driver, 'vehicle.vehicle-a1'))->toBeTrue()
        ->and(socketChannelsAllows($driver, 'vehicle.vehicle-a2'))->toBeFalse()
        ->and(socketChannelsAllows($driver, 'device.device-a1'))->toBeTrue()
        ->and(socketChannelsAllows($driver, 'device.device-a2'))->toBeFalse()
        ->and(socketChannelsAllows($driver, 'device.device-loose'))->toBeFalse()
        ->and(socketChannelsAllows($driver, 'position.position-own'))->toBeTrue()
        ->and(socketChannelsAllows($driver, 'position.position-vehicle'))->toBeTrue()
        ->and(socketChannelsAllows($driver, 'position.position-other'))->toBeFalse();

    // Nothing without a narrow rule, and no customer records.
    expect(socketChannelsAllows($driver, 'waypoint.waypoints-a'))->toBeFalse()
        ->and(socketChannelsAllows($driver, 'place.places-a'))->toBeFalse()
        ->and(socketChannelsAllows($driver, 'contact.contact-a1'))->toBeFalse()
        ->and(socketChannelsAllows($driver, 'customer.contact-a1'))->toBeFalse()
        ->and(socketChannelsAllows($driver, 'vendor.vendors-a'))->toBeFalse();

    // A driver with no current vehicle owns no vehicle records.
    $walker = socketChannelsPrincipal('driver', 'driver-without-vehicle');
    expect(socketChannelsAllows($walker, 'vehicle.vehicles-a'))->toBeFalse();
});

test('a customer reaches their own orders and the driver and vehicle of their active orders', function () {
    socketChannelsBoot();
    $customer = socketChannelsCustomer();

    expect(socketChannelsAllows($customer, 'order.order-a1'))->toBeTrue()
        ->and(socketChannelsAllows($customer, 'order.order_a1'))->toBeTrue()
        ->and(socketChannelsAllows($customer, 'order.order-a3'))->toBeTrue()
        ->and(socketChannelsAllows($customer, 'order.order-a2'))->toBeFalse()
        ->and(socketChannelsAllows($customer, 'order.orders-b'))->toBeFalse();

    // driver-a1 drives an active order of theirs; driver-a2 only a completed one.
    expect(socketChannelsAllows($customer, 'driver.driver-a1'))->toBeTrue()
        ->and(socketChannelsAllows($customer, 'driverAssigned.driver_a1'))->toBeTrue()
        ->and(socketChannelsAllows($customer, 'driver.driver-a3'))->toBeTrue()
        ->and(socketChannelsAllows($customer, 'driver.driver-a2'))->toBeFalse();

    // vehicle-a1 is assigned to an active order; vehicle-a3 is driven by an active order's driver.
    expect(socketChannelsAllows($customer, 'vehicle.vehicle-a1'))->toBeTrue()
        ->and(socketChannelsAllows($customer, 'vehicle.vehicle-a3'))->toBeTrue()
        ->and(socketChannelsAllows($customer, 'vehicle.vehicle-a2'))->toBeFalse();

    // Themselves, and no other contact, device or position.
    expect(socketChannelsAllows($customer, 'contact.contact-a1'))->toBeTrue()
        ->and(socketChannelsAllows($customer, 'customer.contact_a1'))->toBeTrue()
        ->and(socketChannelsAllows($customer, 'contact.contact-a2'))->toBeFalse()
        ->and(socketChannelsAllows($customer, 'customer.contact-a2'))->toBeFalse()
        ->and(socketChannelsAllows($customer, 'device.device-a1'))->toBeFalse()
        ->and(socketChannelsAllows($customer, 'position.position-vehicle'))->toBeFalse()
        ->and(socketChannelsAllows($customer, 'waypoint.waypoints-a'))->toBeFalse();
});

test('other principal kinds are denied by the fleetops resolvers', function () {
    socketChannelsBoot();
    $checkout = socketChannelsPrincipal('checkout', 'checkout-a');
    $resolver = SocketChannels::resolver([Order::class], fn () => true);

    expect($resolver($checkout, 'order-a1', 'order.order-a1'))->toBeFalse()
        ->and($resolver(socketChannelsDriver(), '', 'order.'))->toBeFalse()
        ->and(SocketChannels::resolver([Order::class])(socketChannelsDriver(), 'order-a1'))->toBeFalse()
        ->and(SocketChannels::narrowFor('waypoint'))->toBeNull();

    $order = Order::where('uuid', 'order-a1')->first();
    expect(SocketChannels::narrowOrder($checkout, $order))->toBeFalse()
        ->and(SocketChannels::narrowDriver($checkout, $order))->toBeFalse()
        ->and(SocketChannels::narrowVehicle($checkout, $order))->toBeFalse()
        ->and(SocketChannels::narrowSelf($checkout, $order))->toBeFalse();
});

test('a sanctum user who drives for the current company gets a driver principal', function () {
    socketChannelsBoot();
    $request = Request::create('/v1/socket/token', 'POST');
    $user    = User::where('uuid', 'user-driver-a1')->first();

    session(['company' => 'company-a']);
    $principal = SocketChannels::driverPrincipal($request, $user);

    expect($principal)->toBeInstanceOf(SocketPrincipal::class)
        ->and($principal->kind)->toBe('driver')
        ->and($principal->sub)->toBe('driver-a1')
        ->and($principal->ids)->toBe(['driver-a1', 'driver_a1', 'user-driver-a1', 'user_drivera1'])
        ->and($principal->cid)->toBe('company-a')
        ->and($principal->cpid)->toBe('company_a')
        ->and($principal->env)->toBe('live')
        ->and($principal->adm)->toBeFalse();

    // The registry offers the same principal to the token endpoint.
    expect(app(SocketChannelRegistry::class)->resolvePrincipal($request, $user)?->sub)->toBe('driver-a1');

    // Without a session company the user's own company is used.
    session(['company' => null]);
    expect(SocketChannels::driverPrincipal($request, $user)?->sub)->toBe('driver-a1');

    // A driver of another company, an office user, a stranger object: no claim.
    session(['company' => 'company-b']);
    expect(SocketChannels::driverPrincipal($request, $user))->toBeNull();

    session(['company' => 'company-a']);
    expect(SocketChannels::driverPrincipal($request, User::where('uuid', 'user-office-a')->first()))->toBeNull()
        ->and(SocketChannels::driverPrincipal($request, (object) ['uuid' => 'user-driver-a1']))->toBeNull()
        ->and(SocketChannels::driverPrincipal($request, new User()))->toBeNull();

    session(['company' => null]);
    $homeless = User::where('uuid', 'user-driver-a1')->first();
    $homeless->setRawAttributes(array_merge($homeless->getAttributes(), ['company_uuid' => null]));
    expect(SocketChannels::driverPrincipal($request, $homeless))->toBeNull();
});

test('the service provider registers the fleetops channels when the registry is built', function () {
    socketChannelsBoot();
    app()->forgetInstance(SocketChannelRegistry::class);

    $provider = new FleetOpsServiceProvider(app());
    $register = new ReflectionMethod(FleetOpsServiceProvider::class, 'registerSocketChannels');
    $register->setAccessible(true);
    $register->invoke($provider);

    $registry = app(SocketChannelRegistry::class);

    expect($registry->resolve('order'))->not->toBeNull()
        ->and($registry->resolve('position'))->not->toBeNull()
        ->and($registry->resolve('tracking'))->not->toBeNull();
});
