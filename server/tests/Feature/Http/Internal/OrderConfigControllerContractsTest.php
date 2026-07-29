<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderConfigController;
use Fleetbase\FleetOps\Models\OrderConfig;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

// Register the dispatcher before any model in this file boots so the
// uuid/public-id creating hooks are captured for the real-helpers test.
if (!EloquentModel::getEventDispatcher()) {
    EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
}

class FleetOpsInternalOrderConfigCreateRequestFake
{
    public array $rules;
    public array $validationResponses = [];

    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    public function rules(): array
    {
        return $this->rules;
    }

    public function responseWithErrors($validator)
    {
        $this->validationResponses[] = $validator;

        return ['validation_errors' => true];
    }
}

class FleetOpsInternalOrderConfigValidatorFake
{
    public bool $fails;

    public function __construct(bool $fails)
    {
        $this->fails = $fails;
    }

    public function fails(): bool
    {
        return $this->fails;
    }
}

class FleetOpsInternalOrderConfigControllerCreateProbe extends OrderConfigController
{
    public FleetOpsInternalOrderConfigCreateRequestFake $fakeCreateRequest;
    public FleetOpsInternalOrderConfigValidatorFake $validator;
    public ?OrderConfig $createdRecord = null;
    public ?Throwable $createError     = null;
    public array $validatorPayloads    = [];
    public array $createRequests       = [];

    public function __construct()
    {
        $this->fakeCreateRequest = new FleetOpsInternalOrderConfigCreateRequestFake([
            'name' => ['required'],
            'key'  => ['required'],
        ]);
        $this->validator = new FleetOpsInternalOrderConfigValidatorFake(false);
    }

    protected function createOrderConfigRequest(Request $request)
    {
        return $this->fakeCreateRequest;
    }

    protected function makeOrderConfigValidator(Request $request, array $rules)
    {
        $this->validatorPayloads[] = [$request->input('orderConfig'), $rules];

        return $this->validator;
    }

    protected function createOrderConfigRecord(Request $request)
    {
        $this->createRequests[] = $request;

        if ($this->createError) {
            throw $this->createError;
        }

        return $this->createdRecord;
    }

    protected function createdOrderConfigResource($record): array
    {
        return ['order_config' => $record->uuid];
    }

    protected function errorResponse($message)
    {
        return ['error' => $message];
    }
}

function fleetopsInternalOrderConfigCreatePayload(): Request
{
    return new Request([
        'orderConfig' => [
            'name' => 'Same Day',
            'key'  => 'same-day',
        ],
    ]);
}

test('internal order config controller creates records from validated payloads', function () {
    $controller = new FleetOpsInternalOrderConfigControllerCreateProbe();
    $record     = new OrderConfig();
    $record->setRawAttributes(['uuid' => 'order-config-uuid'], true);
    $controller->createdRecord = $record;

    $request  = fleetopsInternalOrderConfigCreatePayload();
    $response = $controller->createRecord($request);

    expect($response)->toBe(['order_config' => 'order-config-uuid'])
        ->and($controller->validatorPayloads)->toBe([
            [
                ['name' => 'Same Day', 'key' => 'same-day'],
                ['name' => ['required'], 'key' => ['required']],
            ],
        ])
        ->and($controller->createRequests)->toBe([$request]);
});

test('internal order config controller returns request validation responses before creating records', function () {
    $controller            = new FleetOpsInternalOrderConfigControllerCreateProbe();
    $controller->validator = new FleetOpsInternalOrderConfigValidatorFake(true);

    $response = $controller->createRecord(fleetopsInternalOrderConfigCreatePayload());

    expect($response)->toBe(['validation_errors' => true])
        ->and($controller->createRequests)->toBe([])
        ->and($controller->fakeCreateRequest->validationResponses)->toBe([$controller->validator]);
});

test('internal order config controller converts model creation exceptions into error responses', function () {
    $controller              = new FleetOpsInternalOrderConfigControllerCreateProbe();
    $controller->createError = new RuntimeException('Order config name already exists.');

    expect($controller->createRecord(fleetopsInternalOrderConfigCreatePayload()))->toBe([
        'error' => 'Order config name already exists.',
    ]);
});

class FleetOpsInternalOrderConfigRealHelpersProbe extends OrderConfigController
{
    public function __construct()
    {
        $this->model    = new OrderConfig();
        $this->resource = Fleetbase\FleetOps\Http\Resources\v1\OrderConfig::class;
    }
}

function fleetopsInternalOrderConfigDbBoot(): SQLiteConnection
{
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
    if (!Request::hasMacro('array')) {
        Request::macro('array', fn (string $key, $default = []) => (array) $this->input($key, $default));
    }
    if (!class_exists('Illuminate\\Validation\\Rule', false)) {
        eval('namespace Illuminate\\Validation; class Rule { public static function __callStatic($method, $arguments) { return new class { public function __call($method, $arguments) { return $this; } }; } }');
    }

    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }
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
    $schema->create('companies', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'name', 'country', 'options'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });
    $schema->create('order_configs', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'created_by_uuid', 'author_uuid', 'category_uuid', 'icon_uuid', 'name', 'key', 'namespace', 'description', 'tags', 'flow', 'entities', 'meta', 'version', 'status', 'type', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->integer('core_service')->nullable();
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    session(['company' => 'company-1']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme']);
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());
    $request = Request::create('/int/v1/order-configs', 'POST');
    $request->setLaravelSession(app('session.store'));
    app()->instance('request', $request);
    app()->instance('validator', new class {
        public function make($data = [], $rules = [], $messages = [], $attributes = [])
        {
            return new class {
                public function fails()
                {
                    return false;
                }

                public function errors()
                {
                    return new Illuminate\Support\MessageBag([]);
                }

                public function __call($method, $arguments)
                {
                    return $this;
                }
            };
        }

        public function __call($method, $arguments)
        {
            return $this;
        }
    });
    Illuminate\Support\Facades\Validator::clearResolvedInstance('validator');

    return $connection;
}

test('internal order config controller distinguishes validation and query exceptions', function () {
    $controller              = new FleetOpsInternalOrderConfigControllerCreateProbe();
    $controller->createError = new Fleetbase\Exceptions\FleetbaseRequestValidationException(['name' => ['The name is already taken.']]);
    expect($controller->createRecord(fleetopsInternalOrderConfigCreatePayload()))->toBe([
        'error' => ['name' => ['The name is already taken.']],
    ]);

    $controller->createError = new Illuminate\Database\QueryException('mysql', 'insert into order_configs', [], new RuntimeException('duplicate key'));
    $response                = $controller->createRecord(fleetopsInternalOrderConfigCreatePayload());
    expect($response['error'])->toContain('duplicate key');
});

test('internal order config controller real helpers create and delete records', function () {
    $connection = fleetopsInternalOrderConfigDbBoot();
    $controller = new FleetOpsInternalOrderConfigRealHelpersProbe();

    // The real request/validator/model helpers persist a config record
    $request = Request::create('/int/v1/order-configs', 'POST', [
        'orderConfig' => ['name' => 'Contract Freight', 'key' => 'contract-freight'],
    ]);
    $request->setLaravelSession(app('session.store'));
    $created = $controller->createRecord($request);

    expect($created)->toBeArray()->toHaveKey('order_config')
        ->and($connection->table('order_configs')->count())->toBe(1);

    // Core-service configs refuse deletion; regular configs delete cleanly
    $uuid = (string) $connection->table('order_configs')->value('uuid');
    $connection->table('order_configs')->where('uuid', $uuid)->update(['core_service' => 1]);
    $rejected = $controller->deleteRecord($uuid, Request::create('/int/v1/order-configs', 'DELETE'));
    expect(json_encode($rejected->getData(true)))->toContain('cannot be deleted');

    $connection->table('order_configs')->where('uuid', $uuid)->update(['core_service' => 0]);
    $deleted = $controller->deleteRecord($uuid, Request::create('/int/v1/order-configs', 'DELETE'));
    expect($connection->table('order_configs')->whereNull('deleted_at')->count())->toBe(0);

    $missing = $controller->deleteRecord('missing-uuid', Request::create('/int/v1/order-configs', 'DELETE'));
    expect(json_encode($missing->getData(true)))->toContain('No order config found');
});
