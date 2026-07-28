<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\Models\env')) {
    eval('namespace Fleetbase\Models; function env($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\env')) {
    eval('namespace Fleetbase\FleetOps\Models; function env($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Internal\v1\env')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1; function env($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\Models\asset')) {
    eval('namespace Fleetbase\Models; function asset($path = null, $secure = null) { return "https://assets.example.com/" . ltrim((string) $path, "/"); }');
}

if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController;
use Fleetbase\FleetOps\Http\Resources\v1\Proof as ProofResource;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;

/**
 * Covers the internal OrderController capturePhoto endpoint against SQLite
 * with a real validation factory so the closure photo rules execute:
 * base64 photo captures persisting proofs and stored files, waypoint
 * subject resolution, invalid photo strings rejected with 422 errors, and
 * the missing order and empty photo branches.
 */
if (!Str::hasMacro('humanize')) {
    Str::macro('humanize', fn ($value) => ucfirst(str_replace(['_', '-'], ' ', Str::snake((string) $value))));
}

function fleetopsCapturePhotoContainer(): void
{
    $current = Illuminate\Container\Container::getInstance();
    if (method_exists($current, 'environment')) {
        return;
    }

    // Resource lifecycle serialization calls app()->environment(), which the
    // harness container lacks — swap in a subclass carrying the same state
    $replacement = new class extends Illuminate\Container\Container {
        public function environment(...$environments)
        {
            if (empty($environments)) {
                return 'testing';
            }

            $checks = is_array($environments[0]) ? $environments[0] : $environments;

            return in_array('testing', $checks, true);
        }
    };

    foreach (['bindings', 'instances', 'aliases', 'abstractAliases', 'resolved', 'extenders', 'tags', 'contextual', 'scopedInstances', 'reboundCallbacks', 'globalBeforeResolvingCallbacks', 'globalResolvingCallbacks', 'globalAfterResolvingCallbacks', 'beforeResolvingCallbacks', 'resolvingCallbacks', 'afterResolvingCallbacks'] as $property) {
        if (!property_exists(Illuminate\Container\Container::class, $property)) {
            continue;
        }
        $reflection = new ReflectionProperty(Illuminate\Container\Container::class, $property);
        $reflection->setAccessible(true);
        if ($reflection->isInitialized($current)) {
            $reflection->setValue($replacement, $reflection->getValue($current));
        }
    }

    Illuminate\Container\Container::setInstance($replacement);
    Illuminate\Support\Facades\Facade::setFacadeApplication($replacement);
}

function fleetopsCapturePhotoBoot(): SQLiteConnection
{
    fleetopsCapturePhotoContainer();
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
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    // A minimal validation factory that enforces the required-photos rule
    // and executes closure rules so the photo validators run for real
    app()->instance('validator', new class {
        public function make($data = [], $rules = [], $messages = [], $attributes = [])
        {
            $errors = [];

            if (array_key_exists('photos', $rules) && (empty($data['photos']) || !is_array($data['photos']))) {
                $errors[] = 'The photos field is required.';
            }

            foreach ((array) ($rules['photos.*'] ?? []) as $rule) {
                if (!$rule instanceof Closure) {
                    continue;
                }

                foreach ((array) ($data['photos'] ?? []) as $index => $value) {
                    $rule('photos.' . $index, $value, function ($message) use (&$errors) {
                        $errors[] = $message;
                    });
                }
            }

            return new class($errors) {
                public function __construct(public array $collected)
                {
                }

                public function fails()
                {
                    return !empty($this->collected);
                }

                public function errors()
                {
                    return new MessageBag(['photos' => $this->collected]);
                }
            };
        }
    });
    Illuminate\Support\Facades\Validator::clearResolvedInstance('validator');

    $disk = new class implements Illuminate\Contracts\Filesystem\Filesystem {
        public array $writes = [];

        public function url($path)
        {
            return 'https://cdn.example.com/' . ltrim((string) $path, '/');
        }

        public function exists($path)
        {
            return true;
        }

        public function get($path)
        {
            return '';
        }

        public function readStream($path)
        {
            return null;
        }

        public function put($path, $contents, $options = [])
        {
            $this->writes[] = $path;

            return true;
        }

        public function writeStream($path, $resource, array $options = [])
        {
            return true;
        }

        public function getVisibility($path)
        {
            return 'public';
        }

        public function setVisibility($path, $visibility)
        {
            return true;
        }

        public function prepend($path, $data)
        {
            return true;
        }

        public function append($path, $data)
        {
            return true;
        }

        public function delete($paths)
        {
            return true;
        }

        public function copy($from, $to)
        {
            return true;
        }

        public function move($from, $to)
        {
            return true;
        }

        public function size($path)
        {
            return 0;
        }

        public function lastModified($path)
        {
            return 0;
        }

        public function files($directory = null, $recursive = false)
        {
            return [];
        }

        public function allFiles($directory = null)
        {
            return [];
        }

        public function directories($directory = null, $recursive = false)
        {
            return [];
        }

        public function allDirectories($directory = null)
        {
            return [];
        }

        public function makeDirectory($path)
        {
            return true;
        }

        public function deleteDirectory($directory)
        {
            return true;
        }
    };
    $storage = new class($disk) {
        public function __construct(public $d)
        {
        }

        public function disk($diskName = null)
        {
            return $this->d;
        }

        public function __call($method, $arguments)
        {
            return $this->d->{$method}(...$arguments);
        }
    };
    app()->instance('filesystem', $storage);
    Illuminate\Support\Facades\Storage::clearResolvedInstance('filesystem');
    $GLOBALS['fleetopsCapturePhotoStorage'] = $disk;

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'orders'    => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'tracking_number_uuid', 'status', 'type', 'dispatched', 'started'],
        'payloads'  => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'type', 'meta', '_key'],
        'places'    => ['uuid', 'public_id', 'company_uuid', 'name', 'location', 'meta', 'type'],
        'waypoints' => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'order', 'type'],
        'entities'  => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'destination_uuid', 'name', 'type'],
        'proofs'    => ['uuid', 'public_id', 'company_uuid', 'order_uuid', 'subject_uuid', 'subject_type', 'file_uuid', 'remarks', 'raw_data', 'data', '_key'],
        'files'     => ['uuid', 'public_id', 'company_uuid', 'uploader_uuid', 'name', 'original_filename', 'extension', 'content_type', 'path', 'bucket', 'disk', 'folder', 'meta', 'type', 'size', 'slug', 'subject_uuid', 'subject_type', '_key'],
        'companies' => ['uuid', 'public_id', 'name', 'country'],
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

    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });

    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    config()->set('filesystems.default', 'local');
    config()->set('filesystems.disks.s3.bucket', 'test-bucket');

    session(['company' => 'company-1', 'user' => 'user-1']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme', 'country' => 'SG']);

    return $connection;
}

function fleetopsCapturePhotoRequest(array $input): Request
{
    $request = Request::create('/int/v1/orders/capture-photo', 'POST', $input);
    $store   = app('session.store');
    $store->put('company', 'company-1');
    $request->setLaravelSession($store);

    return $request;
}

function fleetopsCapturePhotoSeed(SQLiteConnection $connection): void
{
    $connection->table('places')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_photostop', 'company_uuid' => 'company-1', 'name' => 'Stop']);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'public_id' => 'payload_photoone', 'company_uuid' => 'company-1']);
    $connection->table('waypoints')->insert(['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'waypoint_photoone', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => '11111111-1111-4111-8111-111111111111', 'order' => 0]);
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_photoone1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'status' => 'created']);
}

test('base64 photos persist proofs with stored files', function () {
    $connection = fleetopsCapturePhotoBoot();
    fleetopsCapturePhotoSeed($connection);

    $photo  = 'data:image/png;base64,' . base64_encode('fake-png-bytes');
    $result = (new OrderController())->capturePhoto(fleetopsCapturePhotoRequest([
        'photos'  => [$photo],
        'remarks' => 'Photo proof',
        'data'    => ['angle' => 'front'],
    ]), 'order_photoone1');

    expect($result)->toBeInstanceOf(ProofResource::class)
        ->and($connection->table('proofs')->count())->toBe(1)
        ->and($connection->table('files')->count())->toBe(1)
        ->and($connection->table('proofs')->value('subject_uuid'))->toBe('order-1')
        ->and($GLOBALS['fleetopsCapturePhotoStorage']->writes)->toHaveCount(1);
});

test('waypoint subjects resolve for scoped photo captures', function () {
    $connection = fleetopsCapturePhotoBoot();
    fleetopsCapturePhotoSeed($connection);

    $photo  = base64_encode('waypoint-photo-bytes');
    $result = (new OrderController())->capturePhoto(fleetopsCapturePhotoRequest([
        'photo' => $photo,
    ]), 'order_photoone1', 'waypoint_photoone');

    expect($result)->toBeInstanceOf(ProofResource::class)
        ->and($connection->table('proofs')->value('subject_uuid'))->toBe('22222222-2222-4222-8222-222222222222');
});

test('invalid photos missing orders and empty payloads error', function () {
    $connection = fleetopsCapturePhotoBoot();
    fleetopsCapturePhotoSeed($connection);
    $controller = new OrderController();

    // Invalid base64 strings fail the closure rule with a 422
    $invalid = $controller->capturePhoto(fleetopsCapturePhotoRequest(['photos' => ['!!not-base64!!']]), 'order_photoone1');
    expect($invalid->getStatusCode())->toBe(422);

    // No photos at all also fails validation
    $empty = $controller->capturePhoto(fleetopsCapturePhotoRequest([]), 'order_photoone1');
    expect($empty->getStatusCode())->toBe(422);

    // Unknown orders return the error response
    $missing = $controller->capturePhoto(fleetopsCapturePhotoRequest(['photos' => [base64_encode('x')]]), 'order_missing99');
    expect($missing->getStatusCode())->toBeGreaterThanOrEqual(400);
});
