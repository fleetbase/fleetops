<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\session')) {
    eval('namespace Fleetbase\FleetOps\Models; function session($key = null, $default = null) { return $key === "company" ? "company-session" : $default; }');
}

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\Models\Category;
use Fleetbase\Models\Company;
use Fleetbase\Models\CustomField;
use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;

class FleetOpsOrderConfigIdentifierQueryFake
{
    public ?string $companyUuid = null;
    public ?string $identifier  = null;

    public function __construct(public array $records)
    {
    }

    public function where($column, $operator = null, $value = null): self
    {
        if ($column instanceof Closure) {
            $nested = new self($this->records);
            $column($nested);
            $this->identifier = $nested->identifier;

            return $this;
        }

        if ($column === 'company_uuid') {
            $this->companyUuid = $operator;
        }

        if ($column === 'uuid' || $column === 'namespace' || $column === 'public_id' || $column === 'key') {
            $this->identifier = $operator;
        }

        return $this;
    }

    public function orWhere($column, $operator = null, $value = null): self
    {
        return $this->where($column, $operator, $value);
    }

    public function first(): ?OrderConfig
    {
        foreach ($this->records as $record) {
            if ($this->companyUuid && $record->company_uuid !== $this->companyUuid) {
                continue;
            }

            if (
                $record->uuid === $this->identifier
                || $record->namespace === $this->identifier
                || $record->public_id === $this->identifier
                || $record->key === $this->identifier
            ) {
                return $record;
            }
        }

        return null;
    }
}

class FleetOpsResolvableOrderConfigFake extends OrderConfig
{
    public static array $records              = [];
    public static ?OrderConfig $defaultConfig = null;
    public static ?Company $defaultCompany    = null;

    public static function query()
    {
        return new FleetOpsOrderConfigIdentifierQueryFake(static::$records);
    }

    public static function default(?Company $company = null): self
    {
        static::$defaultCompany = $company;

        return static::$defaultConfig;
    }
}

function fleetopsOrderConfigUnitUseInMemoryConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    $connection->getSchemaBuilder()->create('order_configs', function (Blueprint $table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('namespace')->nullable();
        $table->string('key')->nullable();
        $table->string('version')->nullable();
        $table->string('status')->nullable();
        $table->text('flow')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
}

function fleetopsOrderConfigUnitFlow(): array
{
    return [
        [
            'key'        => 'order_created',
            'code'       => 'created',
            'status'     => 'Created',
            'activities' => ['started'],
        ],
        [
            'key'        => 'order_started',
            'code'       => 'started',
            'status'     => 'Started',
            'activities' => ['completed'],
        ],
        [
            'key'        => 'order_completed',
            'code'       => 'completed',
            'status'     => 'Completed',
            'complete'   => true,
            'activities' => ['archived'],
        ],
        [
            'key'      => 'order_archived',
            'code'     => 'archived',
            'status'   => 'Archived',
            'complete' => true,
        ],
        [
            'key'      => 'order_canceled',
            'code'     => 'canceled',
            'status'   => 'Canceled',
            'complete' => false,
        ],
    ];
}

beforeEach(function () {
    fleetopsOrderConfigUnitUseInMemoryConnection();
    FleetOpsResolvableOrderConfigFake::$records        = [];
    FleetOpsResolvableOrderConfigFake::$defaultConfig  = null;
    FleetOpsResolvableOrderConfigFake::$defaultCompany = null;
});

test('order config relationship contracts and context helpers are stable', function () {
    $config = new OrderConfig(['flow' => fleetopsOrderConfigUnitFlow()]);

    expect($config->company())->toBeInstanceOf(BelongsTo::class)
        ->and($config->company()->getRelated())->toBeInstanceOf(Order::class)
        ->and($config->author())->toBeInstanceOf(BelongsTo::class)
        ->and($config->author()->getRelated())->toBeInstanceOf(User::class)
        ->and($config->category())->toBeInstanceOf(BelongsTo::class)
        ->and($config->category()->getRelated())->toBeInstanceOf(Category::class)
        ->and($config->icon())->toBeInstanceOf(BelongsTo::class)
        ->and($config->icon()->getRelated())->toBeInstanceOf(File::class)
        ->and($config->customFields())->toBeInstanceOf(MorphMany::class)
        ->and($config->customFields()->getRelated())->toBeInstanceOf(CustomField::class)
        ->and($config->type)->toBe('order-config');

    expect($config->setOrderContext(new Order(['status' => 'created'])))->toBe($config);
});

test('order config activity helpers cover order waypoint and empty-current branches', function () {
    $config = new OrderConfig(['flow' => fleetopsOrderConfigUnitFlow()]);
    $order  = new Order(['status' => 'completed']);

    expect($config->getOrderContext($order))->toBe($order)
        ->and($config->activities())->toHaveCount(5)
        ->and($config->getCreatedActivity())->toBeInstanceOf(Activity::class)
        ->and($config->getDispatchActivity())->toBeNull()
        ->and($config->currentActivity()->code)->toBe('completed')
        ->and($config->nextActivity())->toHaveCount(0)
        ->and($config->nextFirstActivity())->toBeNull()
        ->and($config->afterNextActivity())->toBeNull()
        ->and($config->getActivityByCode('started')->status)->toBe('Started')
        ->and($config->getCanceledActivity()->status)->toBe('Canceled')
        ->and($config->getCompletedActivity()->status)->toBe('Completed')
        ->and($config->getStartedActivity()->status)->toBe('Started');

    $waypoint = new Waypoint();
    $waypoint->forceFill([
        'payload_uuid' => 'payload-uuid',
    ]);
    $waypoint->setRelation('trackingNumber', (object) ['last_status_code' => 'STARTED']);

    expect($config->currentActivity($waypoint)->code)->toBe('started');

    $empty      = new OrderConfig(['flow' => []]);
    $emptyOrder = new Order(['status' => 'missing']);
    expect($empty->nextActivity($emptyOrder))->toHaveCount(0)
        ->and($empty->nextFirstActivity($emptyOrder))->toBeNull()
        ->and($empty->afterNextActivity($emptyOrder))->toBeNull()
        ->and($empty->previousActivity($emptyOrder))->toHaveCount(0)
        ->and($empty->getCanceledActivity()->code)->toBe('canceled')
        ->and($empty->getCompletedActivity()->code)->toBe('completed')
        ->and($empty->getStartedActivity()->code)->toBe('started')
        ->and(fn () => $empty->getOrderContext())->toThrow(Exception::class, 'No order context found');
});

test('order config identifier resolution and defaults use company scoped fallbacks', function () {
    $default = new FleetOpsResolvableOrderConfigFake();
    $default->forceFill([
        'uuid'         => 'default-config',
        'public_id'    => 'order_config_default',
        'company_uuid' => 'company-session',
        'name'         => 'Transport',
        'namespace'    => 'system:order-config:transport',
        'key'          => 'transport',
    ]);
    $companyConfig = new FleetOpsResolvableOrderConfigFake();
    $companyConfig->forceFill([
        'uuid'         => 'company-config',
        'public_id'    => 'order_config_company',
        'company_uuid' => 'company-explicit',
        'name'         => 'Company Express',
        'namespace'    => 'company:order-config:express',
        'key'          => 'express',
    ]);

    FleetOpsResolvableOrderConfigFake::$records       = [$default, $companyConfig];
    FleetOpsResolvableOrderConfigFake::$defaultConfig = $default;

    $company = new Company();
    $company->forceFill(['uuid' => 'company-explicit']);

    expect(FleetOpsResolvableOrderConfigFake::resolveFromIdentifier(null)->uuid)->toBe('default-config')
        ->and(FleetOpsResolvableOrderConfigFake::$defaultCompany)->toBeNull()
        ->and(FleetOpsResolvableOrderConfigFake::resolveFromIdentifier(['', 'missing', 'order_config_company'], $company)->uuid)->toBe('company-config')
        ->and(FleetOpsResolvableOrderConfigFake::resolveFromIdentifier('company:order-config:express', $company)->uuid)->toBe('company-config')
        ->and(FleetOpsResolvableOrderConfigFake::resolveFromIdentifier('express', $company)->uuid)->toBe('company-config')
        ->and(FleetOpsResolvableOrderConfigFake::resolveFromIdentifier('missing', $company))->toBeNull();
});
