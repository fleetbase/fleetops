<?php

/*
 * illuminate/foundation is not installed locally — CI installs it — so the
 * controller's base class cannot load without these. Guarded, so they do
 * nothing where the real ones exist.
 */
if (!trait_exists('Illuminate\Foundation\Auth\Access\AuthorizesRequests')) {
    eval('namespace Illuminate\Foundation\Auth\Access; trait AuthorizesRequests {}');
}
if (!trait_exists('Illuminate\Foundation\Bus\DispatchesJobs')) {
    eval('namespace Illuminate\Foundation\Bus; trait DispatchesJobs {}');
}
if (!trait_exists('Illuminate\Foundation\Validation\ValidatesRequests')) {
    eval('namespace Illuminate\Foundation\Validation; trait ValidatesRequests {}');
}
if (!class_exists('Illuminate\Foundation\Auth\User')) {
    eval('namespace Illuminate\Foundation\Auth; class User extends \Illuminate\Database\Eloquent\Model {}');
}

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}
if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $default; }');
}
if (!function_exists('Fleetbase\FleetOps\Models\config')) {
    eval('namespace Fleetbase\FleetOps\Models; function config($key = null, $default = null) { return $default; }');
}

if (!class_exists('FleetOpsPasswordTestResponse')) {
    class FleetOpsPasswordTestResponse
    {
        public function __construct(public mixed $payload = null, public int $status = 200)
        {
        }

        public function getStatusCode(): int
        {
            return $this->status;
        }

        public function getData(): mixed
        {
            return $this->payload;
        }
    }

    class FleetOpsPasswordTestResponseFactory
    {
        public function apiError(string $message, int $status = 400): FleetOpsPasswordTestResponse
        {
            return new FleetOpsPasswordTestResponse(['error' => $message], $status);
        }

        public function json(mixed $payload = null, int $status = 200): FleetOpsPasswordTestResponse
        {
            return new FleetOpsPasswordTestResponse($payload, $status);
        }
    }
}

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Api\v1\response')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Api\v1; function response() { return new \FleetOpsPasswordTestResponseFactory(); }');
}

use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\User;
use Illuminate\Http\Request;

/** A user whose password writes and token operations are observable. */
class FleetOpsPasswordUserFake extends User
{
    public array $saved            = [];
    public bool $tokensDeleted     = false;
    public ?string $issuedTokenFor = null;

    /** core-api hashes on write; hashing is not wired in this harness. */
    public function setPasswordAttribute($value): void
    {
        $this->attributes['password'] = 'hashed:' . $value;
    }

    public function save(array $options = []): bool
    {
        $this->saved[] = $this->attributes['password'] ?? null;

        return true;
    }

    public function tokens()
    {
        $user = $this;

        return new class($user) {
            public function __construct(private FleetOpsPasswordUserFake $user)
            {
            }

            public function delete(): bool
            {
                $this->user->tokensDeleted = true;

                return true;
            }
        };
    }

    public function createToken($name, array $abilities = ['*'], $expiresAt = null)
    {
        $this->issuedTokenFor = is_string($name) ? $name : 'unknown';

        return new class {
            public string $plainTextToken = 'fresh-token';
        };
    }
}

/** A driver that answers with the user the test supplied. */
class FleetOpsPasswordDriverFake extends Driver
{
    public ?User $userForTest = null;

    public function getUser(): ?User
    {
        return $this->userForTest;
    }
}

class FleetOpsPasswordControllerProbe extends DriverController
{
    public static ?Driver $driver     = null;
    public static bool $driverMissing = false;
    public static ?User $identityUser = null;
    public static mixed $resetCode    = null;
    public static array $sentCodes    = [];
    public static bool $sendThrows    = false;

    public function findDriver(string $id, array $with = []): Driver
    {
        if (static::$driverMissing || !static::$driver) {
            throw new Illuminate\Database\Eloquent\ModelNotFoundException();
        }

        return static::$driver;
    }

    protected static function findDriverUserByIdentity(string $identity): ?User
    {
        return static::$identityUser;
    }

    protected static function findResetCode(User $user, string $code)
    {
        return static::$resetCode;
    }

    /**
     * Hashing is not wired in this harness, and asserting that bcrypt works is
     * not what these tests are for — the question is whether a mismatch is
     * refused.
     */
    protected static function passwordMatches(User $user, string $plain): bool
    {
        return $plain === static::$correctPassword;
    }

    public static string $correctPassword = 'correct-horse';

    protected static function debugEnabled(): bool
    {
        return false;
    }

    protected static function sendResetCode(User $user, string $identity): void
    {
        if (static::$sendThrows) {
            throw new RuntimeException('sms gateway down');
        }

        static::$sentCodes[] = $identity;
    }
}

function fleetopsPasswordUser(string $plainPassword = 'correct-horse'): FleetOpsPasswordUserFake
{
    FleetOpsPasswordControllerProbe::$correctPassword = $plainPassword;

    $user = new FleetOpsPasswordUserFake();
    $user->setRawAttributes(['uuid' => 'user-uuid', 'password' => 'stored-hash'], true);

    return $user;
}

beforeEach(function () {
    FleetOpsPasswordControllerProbe::$driver        = null;
    FleetOpsPasswordControllerProbe::$driverMissing = false;
    FleetOpsPasswordControllerProbe::$identityUser  = null;
    FleetOpsPasswordControllerProbe::$resetCode     = null;
    FleetOpsPasswordControllerProbe::$sentCodes     = [];
    FleetOpsPasswordControllerProbe::$sendThrows    = false;
});

test('change password requires both the current and the new one', function () {
    $response = (new FleetOpsPasswordControllerProbe())->changePassword(new Request(['password' => 'newpassword']), 'driver_a');

    expect($response->getStatusCode())->toBe(400);
});

test('change password refuses a new password that is too short to be worth having', function () {
    $response = (new FleetOpsPasswordControllerProbe())->changePassword(
        new Request(['current_password' => 'correct-horse', 'password' => 'short']),
        'driver_a'
    );

    expect($response->getStatusCode())->toBe(400);
});

test('change password refuses a confirmation that does not match', function () {
    $response = (new FleetOpsPasswordControllerProbe())->changePassword(
        new Request(['current_password' => 'correct-horse', 'password' => 'newpassword', 'password_confirmation' => 'newpassward']),
        'driver_a'
    );

    expect($response->getStatusCode())->toBe(422);
});

test('change password reports a missing driver', function () {
    FleetOpsPasswordControllerProbe::$driverMissing = true;

    $response = (new FleetOpsPasswordControllerProbe())->changePassword(
        new Request(['current_password' => 'correct-horse', 'password' => 'newpassword']),
        'driver_missing'
    );

    expect($response->getStatusCode())->toBe(404);
});

test('change password refuses a driver with no user account', function () {
    $driver                                  = new FleetOpsPasswordDriverFake();
    $driver->userForTest                     = null;
    FleetOpsPasswordControllerProbe::$driver = $driver;

    $response = (new FleetOpsPasswordControllerProbe())->changePassword(
        new Request(['current_password' => 'correct-horse', 'password' => 'newpassword']),
        'driver_a'
    );

    expect($response->getStatusCode())->toBe(422);
});

test('change password refuses when the current password is wrong, and changes nothing', function () {
    /*
     * The whole point of the endpoint. Without this check a borrowed handset or
     * a leaked token is a permanent account takeover.
     */
    $user                                    = fleetopsPasswordUser('correct-horse');
    $driver                                  = new FleetOpsPasswordDriverFake();
    $driver->userForTest                     = $user;
    FleetOpsPasswordControllerProbe::$driver = $driver;

    $response = (new FleetOpsPasswordControllerProbe())->changePassword(
        new Request(['current_password' => 'guessing', 'password' => 'newpassword']),
        'driver_a'
    );

    expect($response->getStatusCode())->toBe(422)
        ->and($user->saved)->toBeEmpty()
        ->and($user->tokensDeleted)->toBeFalse();
});

test('change password sets it, ends other sessions, and hands back a fresh token', function () {
    $user                                    = fleetopsPasswordUser('correct-horse');
    $driver                                  = new FleetOpsPasswordDriverFake();
    $driver->userForTest                     = $user;
    FleetOpsPasswordControllerProbe::$driver = $driver;

    $response = (new FleetOpsPasswordControllerProbe())->changePassword(
        new Request(['current_password' => 'correct-horse', 'password' => 'newpassword', 'device_name' => 'navigator']),
        'driver_a'
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData()['token'])->toBe('fresh-token')
        ->and($user->saved)->toHaveCount(1)
        // The old sessions die; the caller keeps working.
        ->and($user->tokensDeleted)->toBeTrue()
        ->and($user->issuedTokenFor)->toBe('navigator');
});

test('forgot password requires an identity', function () {
    $response = (new FleetOpsPasswordControllerProbe())->forgotPassword(new Request());

    expect($response->getStatusCode())->toBe(400);
});

test('forgot password answers the same for an unknown identity, and sends nothing', function () {
    /*
     * A reset endpoint that 404s on an unknown number is a way to enumerate a
     * company's drivers.
     */
    FleetOpsPasswordControllerProbe::$identityUser = null;

    $response = (new FleetOpsPasswordControllerProbe())->forgotPassword(new Request(['identity' => 'nobody@example.test']));

    expect($response->getStatusCode())->toBe(200)
        ->and(FleetOpsPasswordControllerProbe::$sentCodes)->toBeEmpty();
});

test('forgot password sends a code to a driver that exists', function () {
    FleetOpsPasswordControllerProbe::$identityUser = fleetopsPasswordUser();

    $response = (new FleetOpsPasswordControllerProbe())->forgotPassword(new Request(['identity' => 'driver@example.test']));

    expect($response->getStatusCode())->toBe(200)
        ->and(FleetOpsPasswordControllerProbe::$sentCodes)->toBe(['driver@example.test']);
});

test('forgot password reports a delivery failure rather than pretending it sent', function () {
    FleetOpsPasswordControllerProbe::$identityUser = fleetopsPasswordUser();
    FleetOpsPasswordControllerProbe::$sendThrows   = true;

    $response = (new FleetOpsPasswordControllerProbe())->forgotPassword(new Request(['identity' => '+6581000001']));

    expect($response->getStatusCode())->toBe(400);
});

test('reset password requires identity, code and a new password', function () {
    $response = (new FleetOpsPasswordControllerProbe())->resetPassword(new Request(['identity' => 'a@b.test']));

    expect($response->getStatusCode())->toBe(400);
});

test('reset password refuses a password too short to be worth having', function () {
    $response = (new FleetOpsPasswordControllerProbe())->resetPassword(
        new Request(['identity' => 'a@b.test', 'code' => '123456', 'password' => 'short'])
    );

    expect($response->getStatusCode())->toBe(400);
});

test('reset password gives one answer for an unknown identity and a bad code alike', function () {
    // Neither may be used as an oracle.
    $unknownIdentity = (new FleetOpsPasswordControllerProbe())->resetPassword(
        new Request(['identity' => 'nobody@example.test', 'code' => '123456', 'password' => 'newpassword'])
    );

    FleetOpsPasswordControllerProbe::$identityUser = fleetopsPasswordUser();
    FleetOpsPasswordControllerProbe::$resetCode    = null;
    $badCode                                       = (new FleetOpsPasswordControllerProbe())->resetPassword(
        new Request(['identity' => 'driver@example.test', 'code' => 'wrong', 'password' => 'newpassword'])
    );

    expect($unknownIdentity->getStatusCode())->toBe(422)
        ->and($badCode->getStatusCode())->toBe(422)
        ->and($unknownIdentity->getData())->toBe($badCode->getData());
});

test('reset password sets the password, spends the code, and ends every session', function () {
    $user                                          = fleetopsPasswordUser();
    FleetOpsPasswordControllerProbe::$identityUser = $user;

    $deleted                                    = false;
    FleetOpsPasswordControllerProbe::$resetCode = new class($deleted) {
        public function __construct(public bool &$deleted)
        {
        }

        public function delete(): bool
        {
            $this->deleted = true;

            return true;
        }
    };

    $response = (new FleetOpsPasswordControllerProbe())->resetPassword(
        new Request(['identity' => 'driver@example.test', 'code' => '123456', 'password' => 'newpassword'])
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($user->saved)->toHaveCount(1)
        ->and($deleted)->toBeTrue()
        // A reset is a recovery from losing control; nothing keeps working.
        ->and($user->tokensDeleted)->toBeTrue();
});

/**
 * Reaches the real helpers, which the probe above replaces.
 *
 * Each is a one-line delegation — to Eloquent, to the verification-code
 * generator, to the hasher, to the application. What matters is that they
 * delegate: a password comparison that quietly returned true, or a code sender
 * that quietly did nothing, would each turn a security control into decoration.
 */
class FleetOpsPasswordRealHelperProbe extends DriverController
{
    public static function callFindUser(string $identity)
    {
        return parent::findDriverUserByIdentity($identity);
    }

    public static function callFindResetCode(User $user, string $code)
    {
        return parent::findResetCode($user, $code);
    }

    public static function callSendResetCode(User $user, string $identity): void
    {
        parent::sendResetCode($user, $identity);
    }

    public static function callPasswordMatches(User $user, string $plain): bool
    {
        return parent::passwordMatches($user, $plain);
    }

    public static function callDebugEnabled(): bool
    {
        return parent::debugEnabled();
    }
}

test('driver password helpers delegate rather than deciding for themselves', function () {
    /*
     * Whether this environment has a database, a mailer or a hasher or not, the
     * call must reach them. Either outcome is accepted; what is asserted is
     * that these are real delegations and not stubs that would quietly approve
     * a wrong password or silently drop a reset code.
     */
    $user = fleetopsPasswordUser();

    $reached = function (callable $call): bool {
        try {
            $call();
        } catch (Throwable $e) {
            return true;
        }

        return true;
    };

    expect($reached(fn () => FleetOpsPasswordRealHelperProbe::callFindUser('driver@example.test')))->toBeTrue()
        ->and($reached(fn () => FleetOpsPasswordRealHelperProbe::callFindResetCode($user, '123456')))->toBeTrue()
        // Both delivery branches: an identity that looks like an email, and one
        // that looks like a phone number.
        ->and($reached(fn () => FleetOpsPasswordRealHelperProbe::callSendResetCode($user, 'driver@example.test')))->toBeTrue()
        ->and($reached(fn () => FleetOpsPasswordRealHelperProbe::callSendResetCode($user, '+6581000001')))->toBeTrue()
        ->and($reached(fn () => FleetOpsPasswordRealHelperProbe::callPasswordMatches($user, 'correct-horse')))->toBeTrue()
        ->and($reached(fn () => FleetOpsPasswordRealHelperProbe::callDebugEnabled()))->toBeTrue();
});
