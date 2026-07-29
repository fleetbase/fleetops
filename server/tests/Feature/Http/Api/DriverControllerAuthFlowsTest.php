<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController;
use Fleetbase\FleetOps\Http\Resources\v1\Driver as DriverResource;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Covers the API DriverController authentication flows against SQLite:
 * password login with wrong-password and token-issuing branches, the
 * phone-login SMS attempt with email fallback and no-channel error, and
 * verification-code checking with invalid/bypass/valid variants.
 *
 * The `users.driver` relation used by whereHas('driver') is registered
 * through the core Expandable expand() mechanism — the core model's __call
 * forwards unknown methods to the query builder, bypassing Eloquent's
 * relation resolvers.
 */
class FleetOpsDriverAuthHasherFake implements Illuminate\Contracts\Hashing\Hasher
{
    public bool $checks = true;

    public function info($hashedValue): array
    {
        return [];
    }

    public function make($value, array $options = []): string
    {
        return 'hashed-' . $value;
    }

    public function check($value, $hashedValue, array $options = []): bool
    {
        return $this->checks;
    }

    public function needsRehash($hashedValue, array $options = []): bool
    {
        return false;
    }

    public function verifyConfiguration($value): bool
    {
        return true;
    }
}

class FleetOpsDriverAuthMailerFake
{
    public array $sent = [];

    public function to($users)
    {
        return $this;
    }

    public function send($mailable)
    {
        $this->sent[] = $mailable;

        return null;
    }
}

function fleetopsDriverAuthBoot(): SQLiteConnection
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

    app()->instance('hash', new FleetOpsDriverAuthHasherFake());
    Illuminate\Support\Facades\Hash::clearResolvedInstance('hash');

    app()->instance('mail.manager', new FleetOpsDriverAuthMailerFake());
    Illuminate\Support\Facades\Mail::clearResolvedInstance('mail.manager');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'users'                  => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'password', 'type', 'status', 'avatar_uuid'],
        'drivers'                => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'status', 'online', 'auth_token', 'location', 'meta'],
        'companies'              => ['uuid', 'public_id', 'name', 'options'],
        'verification_codes'     => ['uuid', 'public_id', 'subject_uuid', 'subject_type', 'for', 'code', 'meta', 'status', 'expires_at'],
        'personal_access_tokens' => ['tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'last_used_at', 'expires_at'],
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

    User::expand('driver', function () {
        return $this->hasOne(Driver::class, 'user_uuid', 'uuid')->withoutGlobalScopes();
    });

    session(['company' => 'company-1']);

    $connection->table('users')->insert([
        'uuid'         => 'user-1',
        'company_uuid' => 'company-1',
        'name'         => 'Driver One',
        'email'        => 'driver@example.test',
        'phone'        => '+6591234567',
        'password'     => 'hashed-secret',
    ]);
    $connection->table('drivers')->insert([
        'uuid'         => 'driver-1',
        'public_id'    => 'driver_test',
        'company_uuid' => 'company-1',
        'user_uuid'    => 'user-1',
    ]);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'public_id' => 'company_test', 'name' => 'Acme']);

    return $connection;
}

test('password login rejects bad credentials and issues driver tokens', function () {
    $connection = fleetopsDriverAuthBoot();
    $controller = new DriverController();

    // Wrong password
    app('hash')->checks = false;
    $rejected           = $controller->login(Request::create('/x', 'POST', ['identity' => 'driver@example.test', 'password' => 'nope']));
    expect($rejected)->toBeInstanceOf(JsonResponse::class)
        ->and($rejected->getStatusCode())->toBe(401)
        ->and($rejected->getData(true)['error'])->toContain('Authentication failed');

    // Correct password issues a personal access token on the driver resource
    app('hash')->checks = true;
    $resource           = $controller->login(Request::create('/x', 'POST', ['identity' => '6591234567', 'password' => 'secret']));
    expect($resource)->toBeInstanceOf(DriverResource::class)
        ->and($resource->token)->not->toBeEmpty()
        ->and($connection->table('personal_access_tokens')->value('name'))->toBe('driver-1');
});

test('phone login falls back to email verification and errors without channels', function () {
    $connection = fleetopsDriverAuthBoot();
    $controller = new DriverController();

    // Unknown phone number
    app()->instance('request', Request::create('/x', 'POST', ['phone' => '+000']));
    $missing = $controller->loginWithPhone();
    expect($missing->getData(true)['error'])->toContain('No driver with this phone');

    // SMS transport is unavailable in the harness, so the SMS attempt throws
    // and the controller falls back to the mail-faked email channel.
    app()->instance('request', Request::create('/x', 'POST', ['phone' => '6591234567']));
    $emailFallback = $controller->loginWithPhone();
    expect($emailFallback->getData(true))->toBe(['status' => 'OK', 'method' => 'email'])
        ->and($connection->table('verification_codes')->where('for', 'driver_login')->count())->toBeGreaterThan(0);

    // Without an email address no verification channel remains
    $connection->table('users')->where('uuid', 'user-1')->update(['email' => null]);
    $noChannel = $controller->loginWithPhone();
    expect($noChannel->getData(true)['error'])->toContain('Unable to send SMS Verification code');
});

test('code verification handles unknown users invalid codes bypass and success', function () {
    $connection = fleetopsDriverAuthBoot();
    $controller = new DriverController();

    // Unknown identity
    $unknown = $controller->verifyCode(Request::create('/x', 'POST', ['identity' => 'ghost@example.test', 'code' => '123456']));
    expect($unknown->getData(true)['error'])->toContain('Unable to verify code');

    // Invalid code with no bypass configured
    $invalid = $controller->verifyCode(Request::create('/x', 'POST', ['identity' => 'driver@example.test', 'code' => '999999']));
    expect($invalid->getData(true)['error'])->toContain('Invalid verification code');

    // Bypass code from config authenticates without a stored code
    config()->set('fleetops.navigator.bypass_verification_code', '777777');
    $bypassed = $controller->verifyCode(Request::create('/x', 'POST', ['identity' => 'driver@example.test', 'code' => '777777']));
    expect($bypassed)->toBeInstanceOf(DriverResource::class);

    // Stored verification codes authenticate and persist the auth token
    $connection->table('verification_codes')->insert([
        'uuid'         => 'vc-1',
        'subject_uuid' => 'user-1',
        'code'         => '424242',
        'for'          => 'driver_login',
    ]);
    $verified = $controller->verifyCode(Request::create('/x', 'POST', ['identity' => '6591234567', 'code' => '424242']));
    expect($verified)->toBeInstanceOf(DriverResource::class)
        ->and($verified->token)->not->toBeEmpty()
        ->and($connection->table('drivers')->where('uuid', 'driver-1')->value('auth_token'))->not->toBeEmpty();
});

test('verification failures report to sentry and exhaust every channel', function () {
    $connection = fleetopsDriverAuthBoot();
    $controller = new DriverController();

    $sentry = new class {
        public array $captured = [];

        public function captureException($exception)
        {
            $this->captured[] = $exception;

            return null;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    };
    app()->instance('sentry', $sentry);

    // The SMS attempt fails in the harness and is reported before the
    // controller falls back to the email channel
    app()->instance('request', Request::create('/x', 'POST', ['phone' => '6591234567']));
    $emailFallback = $controller->loginWithPhone();
    expect($emailFallback->getData(true))->toBe(['status' => 'OK', 'method' => 'email'])
        ->and($sentry->captured)->toHaveCount(1);

    // When the mail channel also fails both failures are reported and the
    // controller surfaces the generic error
    app()->instance('mail.manager', new class {
        public function to($users)
        {
            return $this;
        }

        public function send($mailable)
        {
            throw new RuntimeException('mail transport unavailable');
        }
    });
    Illuminate\Support\Facades\Mail::clearResolvedInstance('mail.manager');

    $noChannel = $controller->loginWithPhone();
    expect($noChannel->getData(true)['error'])->toContain('Unable to send SMS Verification code')
        ->and(count($sentry->captured))->toBeGreaterThanOrEqual(3);

    app()->forgetInstance('sentry');
});

test('verify code delegates driver creation requests to the create flow', function () {
    fleetopsDriverAuthBoot();

    $probe = new class extends DriverController {
        public array $created = [];

        public function create(Request $request)
        {
            $this->created[] = $request->input('identity');

            return 'delegated-create';
        }
    };

    $result = $probe->verifyCode(Request::create('/x', 'POST', [
        'identity' => 'newdriver@example.test',
        'code'     => '123456',
        'for'      => 'create_driver',
    ]));

    expect($result)->toBe('delegated-create')
        ->and($probe->created)->toBe(['newdriver@example.test']);
});

test('token issuance failures surface as api errors', function () {
    $connection = fleetopsDriverAuthBoot();
    $controller = new DriverController();

    // Without the token table the sanctum call fails; the login endpoint
    // reports the driver-facing error instead of leaking a query exception
    $connection->getSchemaBuilder()->drop('personal_access_tokens');
    app('hash')->checks = true;

    $response = $controller->login(Request::create('/x', 'POST', [
        'identity' => 'driver@example.test',
        'password' => 'secret',
    ]));

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getData(true))->toHaveKey('error');
});
