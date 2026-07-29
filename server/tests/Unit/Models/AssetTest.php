<?php

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    eval('namespace Illuminate\Foundation\Auth; class User extends \Illuminate\Database\Eloquent\Model {}');
}

if (!function_exists('Fleetbase\FleetOps\Models\activity')) {
    eval('namespace Fleetbase\FleetOps\Models; function activity($logName = null) { return new class($logName) { public function performedOn($subject) { return $this; } public function withProperties(array $properties) { return $this; } public function log(string $message) { return true; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\auth')) {
    eval('namespace Fleetbase\FleetOps\Models; function auth() { return new class { public function id() { return "asset-test-user"; } }; }');
}

use Fleetbase\FleetOps\Models\Asset;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\Part;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\FleetOps\Models\Sensor;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Warranty;
use Fleetbase\Models\Category;
use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

class FleetOpsAssetUpdatingFake extends Asset
{
    public array $updates = [];

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }
}

class FleetOpsAssetScopeBuilderFake
{
    public array $calls = [];

    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        $this->calls[] = ['where', $column, $operator, $value];

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->calls[] = ['whereNotNull', $column];

        return $this;
    }

    public function whereHas(string $relation, callable $callback): self
    {
        $related = new class {
            public array $calls = [];

            public function online(): self
            {
                $this->calls[] = 'online';

                return $this;
            }
        };

        $callback($related);
        $this->calls[] = ['whereHas', $relation, $related->calls];

        return $this;
    }
}

class FleetOpsAssetMaintenanceRelationFake extends HasMany
{
    public array $wheres  = [];
    public array $orders  = [];

    public function __construct(
        public bool $overdueExists = false,
        public ?Maintenance $completed = null,
        public ?Maintenance $scheduled = null,
    ) {
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        $this->wheres[] = [$column, $operator, $value, $boolean];

        return $this;
    }

    public function orderBy($column, $direction = 'asc')
    {
        $this->orders[] = [$column, $direction];

        return $this;
    }

    public function exists()
    {
        return $this->overdueExists;
    }

    public function first($columns = ['*'])
    {
        $status = collect($this->wheres)->firstWhere(0, 'status')[1] ?? null;

        return match ($status) {
            'completed' => $this->completed,
            'scheduled' => $this->scheduled,
            default     => null,
        };
    }
}

class FleetOpsAssetMaintenanceFake extends Asset
{
    public bool $overdueExists      = false;
    public ?Maintenance $completed  = null;
    public ?Maintenance $scheduled  = null;
    public array $relationFakes     = [];

    public function maintenances(): HasMany
    {
        $relation              = new FleetOpsAssetMaintenanceRelationFake($this->overdueExists, $this->completed, $this->scheduled);
        $this->relationFakes[] = $relation;

        return $relation;
    }
}

function fleetopsAssetUseRelationConnection(bool $withTables = false): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    return $connection;
}

function fleetopsAsset(array $attributes = []): Asset
{
    $asset = new Asset();
    $asset->setRawAttributes(array_merge([
        'uuid'          => 'asset-uuid',
        'public_id'     => 'asset_public',
        'company_uuid'  => 'company-uuid',
        'odometer'      => 1000,
        'engine_hours'  => 120,
        'specs'         => [],
    ], $attributes), true);
    $asset->setAppends([]);

    return $asset;
}

function fleetopsAssetMaintenance(array $attributes = []): Maintenance
{
    $maintenance = new Maintenance();
    $maintenance->setRawAttributes($attributes, true);
    $maintenance->setAppends([]);

    return $maintenance;
}

test('asset relationship contracts resolve expected relation types and related models', function () {
    fleetopsAssetUseRelationConnection();

    $asset = new Asset();

    expect($asset->assignedTo())->toBeInstanceOf(MorphTo::class)
        ->and($asset->operator())->toBeInstanceOf(MorphTo::class)
        ->and($asset->category())->toBeInstanceOf(BelongsTo::class)
        ->and($asset->category()->getRelated())->toBeInstanceOf(Category::class)
        ->and($asset->vendor())->toBeInstanceOf(BelongsTo::class)
        ->and($asset->vendor()->getRelated())->toBeInstanceOf(Vendor::class)
        ->and($asset->warranty())->toBeInstanceOf(BelongsTo::class)
        ->and($asset->warranty()->getRelated())->toBeInstanceOf(Warranty::class)
        ->and($asset->telematic())->toBeInstanceOf(BelongsTo::class)
        ->and($asset->telematic()->getRelated())->toBeInstanceOf(Telematic::class)
        ->and($asset->currentPlace())->toBeInstanceOf(BelongsTo::class)
        ->and($asset->currentPlace()->getRelated())->toBeInstanceOf(Place::class)
        ->and($asset->photo())->toBeInstanceOf(BelongsTo::class)
        ->and($asset->photo()->getRelated())->toBeInstanceOf(File::class)
        ->and($asset->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and($asset->createdBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($asset->updatedBy())->toBeInstanceOf(BelongsTo::class)
        ->and($asset->updatedBy()->getRelated())->toBeInstanceOf(User::class);
});

test('asset collection relationship contracts keep their intended keys and morphs', function () {
    fleetopsAssetUseRelationConnection();

    $asset = new Asset();

    expect($asset->devices())->toBeInstanceOf(HasMany::class)
        ->and($asset->devices()->getRelated())->toBeInstanceOf(Device::class)
        ->and($asset->devices()->getForeignKeyName())->toBe('attachable_uuid')
        ->and($asset->equipments())->toBeInstanceOf(HasMany::class)
        ->and($asset->equipments()->getRelated())->toBeInstanceOf(Equipment::class)
        ->and($asset->equipments()->getForeignKeyName())->toBe('equipable_uuid')
        ->and($asset->maintenances())->toBeInstanceOf(HasMany::class)
        ->and($asset->maintenances()->getRelated())->toBeInstanceOf(Maintenance::class)
        ->and($asset->maintenances()->getForeignKeyName())->toBe('maintainable_uuid')
        ->and($asset->sensors())->toBeInstanceOf(HasMany::class)
        ->and($asset->sensors()->getRelated())->toBeInstanceOf(Sensor::class)
        ->and($asset->sensors()->getForeignKeyName())->toBe('sensorable_uuid')
        ->and($asset->parts())->toBeInstanceOf(MorphMany::class)
        ->and($asset->parts()->getRelated())->toBeInstanceOf(Part::class)
        ->and($asset->positions())->toBeInstanceOf(HasMany::class)
        ->and($asset->positions()->getRelated())->toBeInstanceOf(Position::class)
        ->and($asset->positions()->getForeignKeyName())->toBe('subject_uuid');
});

test('asset accessors expose related names locations display names online state and options', function () {
    $asset = fleetopsAsset([
        'make'         => 'Freightliner',
        'model'        => 'Cascadia',
        'year'         => 2026,
        'code'         => 'TRK-26',
        'engine_hours' => 48,
    ]);

    $asset->setRelation('category', (object) ['name' => 'Tractor']);
    $asset->setRelation('vendor', (object) ['name' => 'Vendor Ops']);
    $asset->setRelation('warranty', (object) ['name' => 'Extended']);
    $asset->setRelation('photo', (object) ['url' => 'https://cdn.example/asset.png']);
    $asset->setRelation('currentPlace', (object) [
        'name'      => 'Main Yard',
        'address'   => '1 Yard Road',
        'latitude'  => 1.3,
        'longitude' => 103.8,
    ]);
    $asset->setRelation('telematic', (object) ['is_online' => true]);

    expect($asset->category_name)->toBe('Tractor')
        ->and($asset->vendor_name)->toBe('Vendor Ops')
        ->and($asset->warranty_name)->toBe('Extended')
        ->and($asset->photo_url)->toBe('https://cdn.example/asset.png')
        ->and($asset->current_location)->toBe([
            'name'      => 'Main Yard',
            'address'   => '1 Yard Road',
            'latitude'  => 1.3,
            'longitude' => 103.8,
        ])
        ->and($asset->display_name)->toBe('Freightliner Cascadia 2026 TRK-26')
        ->and($asset->is_online)->toBeTrue()
        ->and($asset->getUtilizationRate(2))->toBe(100.0)
        ->and($asset->getSlugOptions())->toBeInstanceOf(Spatie\Sluggable\SlugOptions::class)
        ->and($asset->getActivitylogOptions())->toBeInstanceOf(Spatie\Activitylog\LogOptions::class);

    $asset->setRelation('currentPlace', null);
    $asset->setRelation('telematic', (object) [
        'is_online'     => false,
        'last_location' => ['latitude' => 1.31, 'longitude' => 103.81],
    ]);

    expect($asset->current_location)->toBe(['latitude' => 1.31, 'longitude' => 103.81])
        ->and($asset->is_online)->toBeFalse();

    $unnamed = fleetopsAsset([
        'public_id' => 'asset_fallback',
        'make'      => null,
        'model'     => null,
        'year'      => null,
        'code'      => null,
    ]);

    expect($unnamed->current_location)->toBeNull()
        ->and($unnamed->display_name)->toBe('Asset #asset_fallback')
        ->and($unnamed->getUtilizationRate())->toBe(16.666666666666664);
});

test('asset scopes apply type status telematics and online constraints', function () {
    $asset   = new Asset();
    $builder = new FleetOpsAssetScopeBuilderFake();

    expect($asset->scopeByType($builder, 'trailer'))->toBe($builder)
        ->and($asset->scopeActive($builder))->toBe($builder)
        ->and($asset->scopeWithTelematics($builder))->toBe($builder)
        ->and($asset->scopeOnline($builder))->toBe($builder)
        ->and($builder->calls)->toBe([
            ['where', 'type', 'trailer', null],
            ['where', 'status', 'active', null],
            ['whereNotNull', 'telematic_uuid'],
            ['whereHas', 'telematic', ['online']],
        ]);
});

test('asset odometer and engine hour updates guard stale readings and log successful updates', function () {
    $asset = new FleetOpsAssetUpdatingFake();
    $asset->setRawAttributes([
        'uuid'         => 'asset-uuid',
        'odometer'     => 1000,
        'engine_hours' => 120,
    ], true);
    $asset->setAppends([]);

    expect($asset->updateOdometer(999))->toBeFalse()
        ->and($asset->updateEngineHours(119))->toBeFalse()
        ->and($asset->updates)->toBe([])
        ->and($asset->updateOdometer(1250, 'telematics'))->toBeTrue()
        ->and($asset->updateEngineHours(150, 'telematics'))->toBeTrue()
        ->and($asset->updates)->toBe([
            ['odometer' => 1250],
            ['engine_hours' => 150],
        ]);
});

test('asset maintenance helpers detect overdue and interval maintenance and schedule rows', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00'));
    $asset = new FleetOpsAssetMaintenanceFake();
    $asset->setRawAttributes([
        'uuid'         => 'asset-interval',
        'company_uuid' => 'company-uuid',
        'odometer'     => 5500,
        'engine_hours' => 300,
        'specs'        => ['maintenance_interval' => 500],
    ], true);
    $asset->setAppends([]);
    $asset->completed = fleetopsAssetMaintenance([
        'uuid'         => 'completed-maintenance',
        'status'       => 'completed',
        'completed_at' => Carbon::parse('2026-07-01 10:00:00'),
        'odometer'     => 4800,
    ]);
    $asset->scheduled = fleetopsAssetMaintenance([
        'uuid'         => 'scheduled-maintenance',
        'status'       => 'scheduled',
        'scheduled_at' => Carbon::parse('2026-08-05 10:00:00'),
    ]);

    expect($asset->last_maintenance)->toBeInstanceOf(Maintenance::class)
        ->and($asset->last_maintenance->uuid)->toBe('completed-maintenance')
        ->and($asset->next_maintenance_due)->toBe('2026-08-05')
        ->and($asset->needsMaintenance())->toBeTrue();

    $freshAsset = new FleetOpsAssetMaintenanceFake();
    $freshAsset->setRawAttributes([
        'uuid'     => 'asset-fresh',
        'odometer' => 1000,
        'specs'    => ['maintenance_interval' => 1000],
    ], true);
    $freshAsset->setAppends([]);

    expect($freshAsset->needsMaintenance())->toBeFalse();

    $freshAsset->overdueExists = true;
    expect($freshAsset->needsMaintenance())->toBeTrue();

    Carbon::setTestNow();
});

test('asset display name prefers explicit names and schedules real maintenance rows', function () {
    // Explicit names win over derived make/model/year names
    $named = fleetopsAsset(['name' => 'Yard Tractor 7']);
    expect($named->display_name)->toBe('Yard Tractor 7');

    // Real maintenance scheduling persists a row bound to the asset
    if (!function_exists('Fleetbase\\FleetOps\\Models\\auth')) {
        eval('namespace Fleetbase\\FleetOps\\Models; function auth() { return new class { public function id() { return "user-1"; } public function user() { return null; } }; }');
    }
    $connection = fleetopsAssetUseRelationConnection();
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
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
    session(['company' => 'company-uuid']);
    $connection->getSchemaBuilder()->create('maintenances', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'maintainable_type', 'maintainable_uuid', 'type', 'status', 'scheduled_at', 'completed_at', 'odometer', 'engine_hours', 'summary', 'notes', 'created_by_uuid', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    $asset       = fleetopsAsset(['odometer' => 4200, 'engine_hours' => 210]);
    $maintenance = $asset->scheduleMaintenance('inspection', new DateTime('2026-08-15 09:00:00'), ['summary' => 'Quarterly inspection']);

    expect($maintenance)->toBeInstanceOf(Maintenance::class)
        ->and($connection->table('maintenances')->count())->toBe(1)
        ->and($connection->table('maintenances')->value('maintainable_uuid'))->toBe('asset-uuid')
        ->and($connection->table('maintenances')->value('summary'))->toBe('Quarterly inspection')
        ->and($connection->table('maintenances')->value('type'))->toBe('inspection')
        ->and($connection->table('maintenances')->value('status'))->toBe('scheduled');
});
