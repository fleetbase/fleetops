<?php

if (!function_exists('Fleetbase\FleetOps\Models\session')) {
    eval('namespace Fleetbase\FleetOps\Models; function session($key = null, $default = null) { return $key === "company" ? "company-part" : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\activity')) {
    eval('namespace Fleetbase\FleetOps\Models; function activity($logName = null) { return \FleetOpsPartActivityFake::start($logName); }');
}

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Models\Asset;
use Fleetbase\FleetOps\Models\Part;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Warranty;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\SQLiteConnection;
use Spatie\Activitylog\LogOptions;
use Spatie\Sluggable\SlugOptions;

class FleetOpsPartActivityFake
{
    public static array $entries = [];

    public static function start(?string $logName = null): self
    {
        static::$entries[] = ['log_name' => $logName];

        return new self(count(static::$entries) - 1);
    }

    public function __construct(private int $index)
    {
    }

    public function performedOn($subject): self
    {
        static::$entries[$this->index]['subject'] = $subject;

        return $this;
    }

    public function withProperties(array $properties): self
    {
        static::$entries[$this->index]['properties'] = $properties;

        return $this;
    }

    public function log(string $message): bool
    {
        static::$entries[$this->index]['message'] = $message;

        return true;
    }
}

class FleetOpsPartQueryFake
{
    public array $calls = [];

    public function where(...$arguments): self
    {
        $this->calls[] = ['where', $arguments];

        return $this;
    }
}

class FleetOpsPartDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }
}

class FleetOpsPartStockFake extends Part
{
    public array $updates             = [];
    public int $lowStockAlertAttempts = 0;

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = [$attributes, $options];
        $this->forceFill($attributes);

        return true;
    }

    protected function createLowStockAlert(): void
    {
        $this->lowStockAlertAttempts++;
    }
}

function fleetopsPartModelUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table vendors (uuid varchar(64), name varchar(64), company_uuid varchar(64), deleted_at datetime null)');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsPartDatabaseProbe($connection));

    return $connection;
}

test('part model exposes relation builders logging and slug contracts', function () {
    fleetopsPartModelUseInMemoryConnection();

    $part = new Part();

    expect($part->getActivitylogOptions())->toBeInstanceOf(LogOptions::class)
        ->and($part->getSlugOptions())->toBeInstanceOf(SlugOptions::class)
        ->and($part->vendor())->toBeInstanceOf(BelongsTo::class)
        ->and($part->warranty())->toBeInstanceOf(BelongsTo::class)
        ->and($part->photo())->toBeInstanceOf(BelongsTo::class)
        ->and($part->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and($part->updatedBy())->toBeInstanceOf(BelongsTo::class)
        ->and($part->asset())->toBeInstanceOf(MorphTo::class);
});

test('part accessors derive names urls inventory state and asset display names', function () {
    $part = new Part([
        'quantity_on_hand' => 4,
        'unit_cost'        => 1250,
        'specs'            => ['low_stock_threshold' => 6],
    ]);

    $warranty = new Warranty();
    $warranty->setRawAttributes(['name' => 'Extended'], true);

    $part->setRelation('vendor', new Vendor(['name' => 'Acme Parts']));
    $part->setRelation('warranty', $warranty);
    $part->setRelation('photo', new class {
        public string $url = 'https://example.test/part.png';
    });
    $part->setRelation('asset', new Asset(['name' => 'Van 12']));

    expect($part->vendor_name)->toBe('Acme Parts')
        ->and($part->warranty_name)->toBe('Extended')
        ->and($part->photo_url)->toBe('https://example.test/part.png')
        ->and($part->total_value)->toBe(5000.0)
        ->and($part->is_in_stock)->toBeTrue()
        ->and($part->is_low_stock)->toBeTrue()
        ->and($part->asset_name)->toBe('Van 12');

    $displayOnlyAsset = new class {
        public string $display_name = 'Display Asset';
    };

    $emptyPart = new Part(['quantity_on_hand' => 0]);
    $emptyPart->setRelation('asset', $displayOnlyAsset);

    expect($emptyPart->is_in_stock)->toBeFalse()
        ->and($emptyPart->asset_name)->toBe('Display Asset');
});

test('part scopes apply inventory and manufacturer constraints', function () {
    $part  = new Part();
    $query = new FleetOpsPartQueryFake();

    expect($part->scopeInStock($query))->toBe($query)
        ->and($part->scopeOutOfStock($query))->toBe($query)
        ->and($part->scopeLowStock($query, 3))->toBe($query)
        ->and($part->scopeByManufacturer($query, 'Bosch'))->toBe($query)
        ->and($query->calls)->toBe([
            ['where', ['quantity_on_hand', '>', 0]],
            ['where', ['quantity_on_hand', '<=', 0]],
            ['where', ['quantity_on_hand', '<=', 3]],
            ['where', ['quantity_on_hand', '>', 0]],
            ['where', ['manufacturer', 'Bosch']],
        ]);
});

test('part stock adjustment helpers guard invalid quantities and log successful updates', function () {
    FleetOpsPartActivityFake::$entries = [];

    $part = new FleetOpsPartStockFake([
        'uuid'             => 'part-uuid',
        'company_uuid'     => 'company-part',
        'sku'              => 'PAD-1',
        'name'             => 'Brake Pad',
        'quantity_on_hand' => 4,
        'specs'            => ['low_stock_threshold' => 3],
    ]);

    expect($part->addStock(0))->toBeFalse()
        ->and($part->addStock(6, 'delivery'))->toBeTrue()
        ->and($part->quantity_on_hand)->toBe(10)
        ->and($part->removeStock(11))->toBeFalse()
        ->and($part->removeStock(8, 'repair'))->toBeTrue()
        ->and($part->quantity_on_hand)->toBe(2)
        ->and($part->lowStockAlertAttempts)->toBe(1)
        ->and($part->setStock(-1))->toBeFalse()
        ->and($part->setStock(9, 'cycle count'))->toBeTrue()
        ->and($part->quantity_on_hand)->toBe(9);

    expect(FleetOpsPartActivityFake::$entries)->toHaveCount(3)
        ->and(FleetOpsPartActivityFake::$entries[0]['log_name'])->toBe('stock_added')
        ->and(FleetOpsPartActivityFake::$entries[0]['properties'])->toMatchArray([
            'old_quantity'   => 4,
            'added_quantity' => 6,
            'new_quantity'   => 10,
            'reason'         => 'delivery',
        ])
        ->and(FleetOpsPartActivityFake::$entries[1]['log_name'])->toBe('stock_removed')
        ->and(FleetOpsPartActivityFake::$entries[2]['log_name'])->toBe('stock_adjusted');
});

test('part compatibility reorder and estimated cost helpers use specs and prices', function () {
    $part = new Part([
        'quantity_on_hand' => 3,
        'unit_cost'        => 2500,
        'msrp'             => 4000,
        'specs'            => [
            'compatible_assets'   => ['van', 'Ford Transit'],
            'low_stock_threshold' => 5,
            'reorder_quantity'    => 12,
        ],
    ]);

    $van = new Asset([
        'type'  => 'van',
        'make'  => 'Mercedes',
        'model' => 'Sprinter',
    ]);

    $transit = new Asset([
        'type'  => 'truck',
        'make'  => 'Ford',
        'model' => 'Transit',
    ]);

    $sedan = new Asset([
        'type'  => 'car',
        'make'  => 'Toyota',
        'model' => 'Camry',
    ]);

    expect($part->isCompatibleWith($van))->toBeTrue()
        ->and($part->isCompatibleWith($transit))->toBeTrue()
        ->and($part->isCompatibleWith($sedan))->toBeFalse()
        ->and((new Part())->isCompatibleWith($sedan))->toBeTrue()
        ->and($part->getReorderPoint())->toBe(5)
        ->and($part->getReorderQuantity())->toBe(12)
        ->and($part->needsReorder())->toBeTrue()
        ->and($part->getEstimatedCost(2))->toBe(5000.0)
        ->and($part->getEstimatedCost(2, true))->toBe(8000.0)
        ->and((new Part())->getReorderPoint())->toBe(5)
        ->and((new Part())->getReorderQuantity())->toBe(10);
});

test('part import maps spreadsheet aliases defaults and vendor lookup', function () {
    $connection = fleetopsPartModelUseInMemoryConnection();
    $connection->table('vendors')->insert([
        'uuid'         => 'vendor-uuid',
        'name'         => 'Acme Supply',
        'company_uuid' => 'company-part',
        'deleted_at'   => null,
    ]);

    $part = Part::createFromImport([
        'part_number'  => 'FLT-42',
        'part_name'    => 'Fleet Filter',
        'brand'        => 'FleetCo',
        'serial'       => 'SER-99',
        'quantity'     => '7',
        'cost'         => 1234,
        'retail_price' => 2345,
        'currency'     => 'sgd',
        'supplier'     => 'Acme',
    ]);

    expect($part)->toBeInstanceOf(Part::class)
        ->and($part->company_uuid)->toBe('company-part')
        ->and($part->sku)->toBe('FLT-42')
        ->and($part->name)->toBe('Fleet Filter')
        ->and($part->type)->toBe('consumable')
        ->and($part->status)->toBe('in_stock')
        ->and($part->manufacturer)->toBe('FleetCo')
        ->and($part->serial_number)->toBe('SER-99')
        ->and($part->quantity_on_hand)->toBe(7)
        ->and($part->unit_cost)->toBe(1234)
        ->and($part->msrp)->toBe(2345)
        ->and($part->currency)->toBe('SGD')
        ->and($part->vendor_uuid)->toBe('vendor-uuid');
});
