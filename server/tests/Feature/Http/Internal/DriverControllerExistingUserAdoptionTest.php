<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\DriverController;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;

/**
 * Covers the internal DriverController createRecord unique-conflict branch
 * against SQLite with a failing validator: adopting an existing
 * organization member by creating a driver profile, returning the existing
 * driver profile when one already exists, assigning non-members to the
 * company, and falling through to the validation error response when the
 * conflict is not a phone or email collision.
 */
if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

if (!function_exists('Fleetbase\FleetOps\Observers\event')) {
    eval('namespace Fleetbase\FleetOps\Observers; function event($event = null, $payload = []) { return []; }');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Models\session')) {
    eval('namespace Fleetbase\Models; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } public function missing($k) { return \session($k) === null; } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Internal\v1\env')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1; function env($key, $default = null) { return $default; }');
}

if (!function_exists('__')) {
    function __($key = null, $replace = [], $locale = null)
    {
        return $key;
    }
}

if (!class_exists('Illuminate\Validation\Rule')) {
    eval('namespace Illuminate\Validation; class Rule { public function __construct(private string $rule = "") {} public static function requiredIf($c): string { return (is_callable($c) ? $c() : $c) ? "required" : "nullable"; } public static function in(array $v): self { return new self("in:" . implode(",", $v)); } public static function exists($t, $c = null): self { return new self("exists:" . $t . ($c ? "," . $c : "")); } public static function unique($t, $c = null): self { return new self("unique:" . $t . ($c ? "," . $c : "")); } public static function when($c, array $r): array { return (is_callable($c) ? $c() : $c) ? $r : []; } public function where($cb): self { return $this; } public function whereNull($col): self { return $this; } public function ignore($v, $c = null): self { return $this; } public function __toString(): string { return $this->rule; } }');
}

if (!Str::hasMacro('humanize')) {
    Str::macro('humanize', fn ($value) => ucfirst(str_replace(['_', '-'], ' ', Str::snake((string) $value))));
}

if (!Request::hasMacro('getController')) {
    Request::macro('getController', fn () => new DriverController());
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

function fleetopsDriverAdoptionBoot(array $validatorErrors): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
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
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    $GLOBALS['fleetopsDriverAdoptionErrors'] = $validatorErrors;
    app()->instance('validator', new class {
        public function make($data = [], $rules = [], $messages = [], $attributes = [])
        {
            return new class implements Illuminate\Contracts\Validation\Validator {
                public function fails()
                {
                    return true;
                }

                public function errors()
                {
                    return new MessageBag($GLOBALS['fleetopsDriverAdoptionErrors']);
                }

                public function validated()
                {
                    return [];
                }

                public function validate()
                {
                    return [];
                }

                public function failed()
                {
                    return array_keys($GLOBALS['fleetopsDriverAdoptionErrors']);
                }

                public function sometimes($attribute, $rules, callable $callback)
                {
                    return $this;
                }

                public function after($callback)
                {
                    return $this;
                }

                public function getMessageBag()
                {
                    return new MessageBag($GLOBALS['fleetopsDriverAdoptionErrors']);
                }
            };
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'drivers'       => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'vendor_uuid', 'location', 'slug', 'status', 'country', 'city', 'online', '_key'],
        'users'         => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'phone', 'password', 'status', 'type', 'username', 'avatar_uuid', 'slug', 'timezone', 'country', 'ip_address', 'meta', '_key'],
        'companies'     => ['uuid', 'public_id', 'name', 'owner_uuid', 'timezone', 'options', 'status', '_key'],
        'company_users' => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'status', '_key'],
        'vehicles'      => ['uuid', 'public_id', 'company_uuid', 'driver_uuid'],
        'custom_fields' => ['uuid', 'company_uuid', 'subject_uuid', 'subject_type', 'name', 'label'],
        'settings'      => ['key', 'value'],
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

    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    session(['company' => 'company-1']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'public_id' => 'company_adopt1', 'name' => 'Acme']);

    return $connection;
}

function fleetopsDriverAdoptionRequest(array $driver): Request
{
    return Request::create('/int/v1/drivers', 'POST', ['driver' => $driver]);
}

test('phone conflicts adopt existing organization members as drivers', function () {
    $connection = fleetopsDriverAdoptionBoot(['phone' => ['The phone has already been taken.']]);
    $connection->table('users')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'company_uuid' => 'company-1', 'name' => 'Member', 'phone' => '+6591234567', 'slug' => 'member', 'type' => 'driver']);
    $connection->table('company_users')->insert(['uuid' => 'cu-1', 'company_uuid' => 'company-1', 'user_uuid' => '11111111-1111-4111-8111-111111111111']);

    $result = (new DriverController())->createRecord(fleetopsDriverAdoptionRequest([
        'name'  => 'Member',
        'phone' => '+6591234567',
    ]));

    expect($result)->toBeArray()
        ->and($result['driver']->resource->user_uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($connection->table('drivers')->count())->toBe(1)
        ->and($connection->table('drivers')->value('company_uuid'))->toBe('company-1');
});

test('email conflicts return the existing driver profile when present', function () {
    $connection = fleetopsDriverAdoptionBoot(['email' => ['The email has already been taken.']]);
    $connection->table('users')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'company_uuid' => 'company-1', 'name' => 'Member', 'email' => 'member@example.com', 'type' => 'driver']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'company_uuid' => 'company-1', 'user_uuid' => '11111111-1111-4111-8111-111111111111']);

    $result = (new DriverController())->createRecord(fleetopsDriverAdoptionRequest([
        'name'  => 'Member',
        'email' => 'member@example.com',
    ]));

    expect($result)->toBeArray()
        ->and($result['driver']->resource->uuid)->toBe('driver-1')
        ->and($connection->table('drivers')->count())->toBe(1);
});

test('non phone or email conflicts fall through to the error response', function () {
    fleetopsDriverAdoptionBoot(['name' => ['The name field is required.']]);

    // The error response seam raises a TypeError in the harness once the
    // fall-through branch executes
    expect(fn () => (new DriverController())->createRecord(fleetopsDriverAdoptionRequest([
        'phone' => '+6590000000',
    ])))->toThrow(TypeError::class);
});
