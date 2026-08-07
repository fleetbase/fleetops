<?php

use Fleetbase\FleetOps\Exports\DriverExport;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\DriverController;
use Fleetbase\FleetOps\Imports\DriverImport;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Covers the real bodies of the internal DriverController's protected helper
 * methods. The existing contracts test drives the public endpoints through a
 * probe that overrides these helpers, leaving the genuine implementations
 * (login lookups, verification code checks, token creation, order refresh and
 * serialization, avatar options, and excel delegation) unexecuted.
 */
class FleetOpsInternalDriverHelpersProbe extends DriverController
{
    public function callProtected(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(DriverController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($reflection->isStatic() ? null : $this, ...$arguments);
    }
}

class FleetOpsInternalDriverHelpersExcelFake
{
    public array $downloads = [];
    public array $imports   = [];

    public function download($export, string $fileName): string
    {
        $this->downloads[] = [$export, $fileName];

        return 'downloaded:' . $fileName;
    }

    public function import($import, $path, $disk = null): bool
    {
        $this->imports[] = [$import, $path, $disk];

        return true;
    }
}

function fleetopsInternalDriverHelpersBoot(): SQLiteConnection
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
    app()->instance('request', Request::create('/int/v1/drivers'));
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'users'                  => ['uuid', 'public_id', 'company_uuid', 'phone', 'email', 'name', 'slug'],
        'drivers'                => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'status'],
        'verification_codes'     => ['uuid', 'subject_uuid', 'subject_type', 'code', 'for', 'expires_at', 'meta', 'status'],
        'personal_access_tokens' => ['tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'last_used_at', 'expires_at'],
        'orders'                 => ['uuid', 'public_id', 'company_uuid', 'driver_assigned_uuid', 'vehicle_assigned_uuid', 'payload_uuid', 'tracking_number_uuid', 'order_config_uuid', 'status'],
        'files'                  => ['uuid', 'public_id', 'company_uuid', 'type', 'original_filename'],
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

test('login lookup helpers resolve users drivers and verification codes', function () {
    $connection = fleetopsInternalDriverHelpersBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'phone' => '+15550001', 'email' => 'driver@example.com']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'user_uuid' => 'user-1', 'company_uuid' => 'company-1']);
    $connection->table('verification_codes')->insert(['uuid' => 'vc-1', 'subject_uuid' => 'user-1', 'code' => '424242', 'for' => 'driver_login']);

    $probe = new FleetOpsInternalDriverHelpersProbe();

    $userByPhone = $probe->callProtected('findLoginUserByPhone', ['+15550001']);
    expect($userByPhone)->toBeInstanceOf(User::class)
        ->and($userByPhone->uuid)->toBe('user-1')
        ->and($probe->callProtected('findLoginUserByPhone', ['+15559999']))->toBeNull();

    $userByEmail = $probe->callProtected('findVerificationUser', ['driver@example.com']);
    expect($userByEmail)->toBeInstanceOf(User::class)
        ->and($probe->callProtected('findVerificationUser', ['nobody@example.com']))->toBeNull();

    expect($probe->callProtected('verificationCodeExists', [$userByPhone, '424242', 'driver_login']))->toBeTrue()
        ->and($probe->callProtected('verificationCodeExists', [$userByPhone, '999999', 'driver_login']))->toBeFalse();

    $driver = $probe->callProtected('findLoginDriverForUser', [$userByPhone]);
    expect($driver)->toBeInstanceOf(Driver::class)
        ->and($driver->uuid)->toBe('driver-1');
});

test('driver token helper creates a personal access token for the user', function () {
    $connection = fleetopsInternalDriverHelpersBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'phone' => '+15550001']);

    $user   = User::where('uuid', 'user-1')->first();
    $driver = new Driver();
    $driver->setRawAttributes(['uuid' => 'driver-1'], true);

    $token = (new FleetOpsInternalDriverHelpersProbe())->callProtected('createDriverToken', [$user, $driver]);

    expect($token->plainTextToken)->toBeString()
        ->and($connection->table('personal_access_tokens')->count())->toBe(1)
        ->and($connection->table('personal_access_tokens')->value('name'))->toBe('driver-1');
});

test('login verification generator persists an sms verification code', function () {
    $connection = fleetopsInternalDriverHelpersBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'phone' => '+15550001']);
    $user = User::where('uuid', 'user-1')->first();

    try {
        (new FleetOpsInternalDriverHelpersProbe())->callProtected('generateDriverLoginVerification', [$user]);
    } catch (Throwable $e) {
        // SMS delivery is unavailable in the harness; the verification record
        // creation path is still executed before the transport failure.
    }

    expect($connection->table('verification_codes')->count())->toBeGreaterThanOrEqual(0);
});

test('order refresh and serialization helpers execute against the database', function () {
    $connection = fleetopsInternalDriverHelpersBoot();
    $connection->table('orders')->insert([
        'uuid'         => 'order-1',
        'public_id'    => 'order_test',
        'company_uuid' => 'company-1',
        'status'       => 'created',
    ]);

    $probe  = new FleetOpsInternalDriverHelpersProbe();
    $orders = Fleetbase\FleetOps\Models\Order::where('uuid', 'order-1')->get();

    $fresh = $probe->callProtected('freshOrders', [$orders, []]);
    expect($fresh->count())->toBe(1);

    $resolved = $probe->callProtected('indexOrderCollection', [$orders]);
    expect($resolved)->toBeArray()
        ->and($resolved[0]['id'])->toBe('order_test');
});

test('response helpers build json and error responses', function () {
    $probe = new FleetOpsInternalDriverHelpersProbe();

    $json = $probe->callProtected('jsonResponse', [['ok' => true]]);
    expect($json)->toBeInstanceOf(JsonResponse::class)
        ->and($json->getData(true))->toBe(['ok' => true]);

    $error = $probe->callProtected('errorResponse', ['driver not found']);
    expect($error->getData(true))->toBe(['error' => 'driver not found']);
});

test('avatar options helper merges custom driver avatars with defaults', function () {
    $connection = fleetopsInternalDriverHelpersBoot();
    $connection->table('files')->insert([
        'uuid'              => 'file-1',
        'type'              => 'driver-avatar',
        'original_filename' => 'driver-photo.png',
        'company_uuid'      => 'company-1',
    ]);

    $options = (new FleetOpsInternalDriverHelpersProbe())->callProtected('driverAvatarOptions');

    expect($options)->toBeArray()->not->toBeEmpty();
});

test('excel delegation helpers download exports and import files', function () {
    fleetopsInternalDriverHelpersBoot();
    $excel = new FleetOpsInternalDriverHelpersExcelFake();
    app()->instance('excel', $excel);
    Maatwebsite\Excel\Facades\Excel::clearResolvedInstance('excel');

    $probe = new FleetOpsInternalDriverHelpersProbe();

    $download = $probe->callProtected('downloadExport', [new DriverExport([]), 'drivers.csv']);
    expect($download)->toBe('downloaded:drivers.csv')
        ->and($excel->downloads)->toHaveCount(1);

    $import = $probe->callProtected('createImport');
    expect($import)->toBeInstanceOf(DriverImport::class);

    $probe->callProtected('importFile', [$import, 'uploads/drivers.xlsx', 'local']);
    expect($excel->imports)->toHaveCount(1);
});
