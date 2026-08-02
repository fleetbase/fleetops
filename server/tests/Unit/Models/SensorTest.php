<?php

if (!function_exists('Fleetbase\FleetOps\Models\auth')) {
    eval('namespace Fleetbase\FleetOps\Models; function auth() { return new class { public function id() { return "user-calibrator"; } }; }');
}

use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Sensor;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Warranty;
use Fleetbase\Models\Alert;
use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

class FleetOpsSensorModelDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }

    public function raw(string $value)
    {
        return $this->connection->raw($value);
    }
}

class FleetOpsSensorModelQueryFake
{
    public array $calls = [];

    public function where(...$arguments): static
    {
        $this->calls[] = ['where', $arguments];

        if (isset($arguments[0]) && is_callable($arguments[0])) {
            $arguments[0]($this);
        }

        return $this;
    }

    public function whereRaw(string $sql): static
    {
        $this->calls[] = ['whereRaw', $sql];

        return $this;
    }

    public function orWhereRaw(string $sql): static
    {
        $this->calls[] = ['orWhereRaw', $sql];

        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->calls[] = ['whereNotNull', $column];

        return $this;
    }
}

class FleetOpsSensorModelUpdatingFake extends Sensor
{
    public array $updates = [];

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;

        return false;
    }

    public function severityForTest(string $status): string
    {
        return $this->getSeverityForThresholdStatus($status);
    }

    public function messageForTest(mixed $value, string $status): string
    {
        return $this->generateThresholdAlertMessage($value, $status);
    }
}

function fleetopsSensorModelUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsSensorModelDatabaseProbe($connection));
    app()->instance('db.schema', $connection->getSchemaBuilder());

    return $connection;
}

test('sensor model exposes relation builders and logging options', function () {
    fleetopsSensorModelUseInMemoryConnection();

    $sensor = new Sensor();

    expect($sensor->getSlugOptions()->generateSlugFrom)->toBe(['name', 'sensor_type'])
        ->and($sensor->getSlugOptions()->slugField)->toBe('slug')
        ->and($sensor->getActivitylogOptions()->logOnlyDirty)->toBeTrue()
        ->and($sensor->telematic())->toBeInstanceOf(BelongsTo::class)
        ->and($sensor->telematic()->getRelated())->toBeInstanceOf(Telematic::class)
        ->and($sensor->device())->toBeInstanceOf(BelongsTo::class)
        ->and($sensor->device()->getRelated())->toBeInstanceOf(Device::class)
        ->and($sensor->warranty())->toBeInstanceOf(BelongsTo::class)
        ->and($sensor->warranty()->getRelated())->toBeInstanceOf(Warranty::class)
        ->and($sensor->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and($sensor->createdBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($sensor->updatedBy())->toBeInstanceOf(BelongsTo::class)
        ->and($sensor->updatedBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($sensor->sensorable())->toBeInstanceOf(MorphTo::class)
        ->and($sensor->photo())->toBeInstanceOf(BelongsTo::class)
        ->and($sensor->photo()->getRelated())->toBeInstanceOf(File::class)
        ->and($sensor->alerts())->toBeInstanceOf(HasMany::class)
        ->and($sensor->alerts()->getRelated())->toBeInstanceOf(Alert::class);
});

test('sensor accessors normalize activity state names photos and thresholds', function () {
    Carbon::setTestNow('2026-07-27 10:00:00');

    try {
        $device = new Device();
        $device->setRawAttributes(['name' => 'Cold chain gateway'], true);

        $warranty = new Warranty();
        $warranty->setRawAttributes(['name' => 'Two year coverage'], true);

        $photo = (object) ['url' => 'https://example.test/photo.png'];

        $attached = new class extends EloquentModel {
            protected $guarded = [];
        };
        $attached->setRawAttributes(['display_name' => 'Trailer 7'], true);

        $sensor = new Sensor();
        $sensor->forceFill([
            'status'               => 'active',
            'last_reading_at'      => Carbon::parse('2026-07-27 09:59:00'),
            'report_frequency_sec' => 60,
            'last_value'           => 8,
            'min_threshold'        => 3,
            'max_threshold'        => 9,
            'threshold_inclusive'  => true,
            'unit'                 => 'C',
        ]);
        $sensor->setRelation('device', $device);
        $sensor->setRelation('warranty', $warranty);
        $sensor->setRelation('photo', $photo);
        $sensor->setRelation('sensorable', $attached);

        expect($sensor->device_name)->toBe('Cold chain gateway')
            ->and($sensor->warranty_name)->toBe('Two year coverage')
            ->and($sensor->photo_url)->toBe('https://example.test/photo.png')
            ->and($sensor->attached_to_name)->toBe('Trailer 7')
            ->and($sensor->is_active)->toBeTrue()
            ->and($sensor->threshold_status)->toBe('normal')
            ->and($sensor->last_reading_formatted)->toBe('8 C');

        $inactive = new Sensor();
        $inactive->forceFill(['status' => 'maintenance']);

        $stale = new Sensor();
        $stale->forceFill([
            'status'               => 'active',
            'last_reading_at'      => Carbon::parse('2026-07-27 09:00:00'),
            'report_frequency_sec' => 60,
        ]);

        expect($inactive->is_active)->toBeFalse()
            ->and($stale->is_active)->toBeFalse()
            ->and((new Sensor())->attached_to_name)->toBeNull()
            ->and((new Sensor())->last_reading_formatted)->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

test('sensor threshold status handles inclusive and exclusive boundaries', function (array $attributes, string $expected) {
    $sensor = new Sensor();
    $sensor->forceFill($attributes);

    expect($sensor->threshold_status)->toBe($expected);
})->with([
    'empty value'                     => [['last_value' => null, 'min_threshold' => 1, 'max_threshold' => 2], 'normal'],
    'inclusive range low miss'        => [['last_value' => 0.5, 'min_threshold' => 1, 'max_threshold' => 5, 'threshold_inclusive' => true], 'out_of_range'],
    'exclusive range boundary miss'   => [['last_value' => 1, 'min_threshold' => 1, 'max_threshold' => 5, 'threshold_inclusive' => false], 'out_of_range'],
    'minimum only miss'               => [['last_value' => 1, 'min_threshold' => 2, 'threshold_inclusive' => true], 'below_minimum'],
    'minimum exclusive boundary miss' => [['last_value' => 2, 'min_threshold' => 2, 'threshold_inclusive' => false], 'below_minimum'],
    'maximum only miss'               => [['last_value' => 10, 'max_threshold' => 8, 'threshold_inclusive' => true], 'above_maximum'],
    'maximum exclusive boundary miss' => [['last_value' => 8, 'max_threshold' => 8, 'threshold_inclusive' => false], 'above_maximum'],
]);

test('sensor scopes write expected query constraints', function () {
    Carbon::setTestNow('2026-07-27 10:00:00');

    try {
        $sensor = new Sensor();
        $query  = new FleetOpsSensorModelQueryFake();

        expect($sensor->scopeByType($query, 'temperature'))->toBe($query)
            ->and($sensor->scopeActive($query))->toBe($query)
            ->and($sensor->scopeWithRecentReadings($query, 30))->toBe($query)
            ->and($sensor->scopeOutOfThreshold($query))->toBe($query)
            ->and($query->calls[0])->toBe(['where', ['sensor_type', 'temperature']])
            ->and($query->calls[1])->toBe(['where', ['status', 'active']])
            ->and($query->calls[2][0])->toBe('where')
            ->and($query->calls[2][1][0])->toBe('last_reading_at')
            ->and($query->calls[2][1][1])->toBe('>=')
            ->and($query->calls)->toContain(['whereRaw', 'CAST(last_value AS DECIMAL) < min_threshold'])
            ->and($query->calls)->toContain(['orWhereRaw', 'CAST(last_value AS DECIMAL) > max_threshold'])
            ->and($query->calls)->toContain(['whereNotNull', 'last_value']);
    } finally {
        Carbon::setTestNow();
    }
});

test('sensor helpers record calibration history and threshold messages', function () {
    Carbon::setTestNow('2026-07-27 10:00:00');

    try {
        $sensor = new FleetOpsSensorModelUpdatingFake();
        $sensor->forceFill([
            'uuid'          => 'sensor-uuid',
            'name'          => 'Fuel level',
            'unit'          => '%',
            'min_threshold' => 20,
            'max_threshold' => 80,
            'last_value'    => 44,
            'calibration'   => ['offset' => 2, 'scale' => 1.5],
        ]);

        expect($sensor->recordReading(55, Carbon::parse('2026-07-27 09:45:00')))->toBeFalse()
            ->and($sensor->updates[0]['last_value'])->toBe(55)
            ->and($sensor->updates[0]['last_reading_at']->toDateTimeString())->toBe('2026-07-27 09:45:00')
            ->and($sensor->calibrate(3, 2))->toBeFalse()
            ->and($sensor->updates[1]['calibration']['offset'])->toBe(3.0)
            ->and($sensor->updates[1]['calibration']['scale'])->toBe(2.0)
            ->and($sensor->updates[1]['calibration']['calibrated_at']->toDateTimeString())->toBe('2026-07-27 10:00:00')
            ->and($sensor->applyCalibratedValue(10))->toBe(17.0)
            ->and($sensor->severityForTest('out_of_range'))->toBe('high')
            ->and($sensor->severityForTest('above_maximum'))->toBe('medium')
            ->and($sensor->severityForTest('normal'))->toBe('low')
            ->and($sensor->messageForTest(90, 'above_maximum'))->toBe("Sensor 'Fuel level' reading (90 %) exceeds maximum threshold (80 %)")
            ->and($sensor->messageForTest(10, 'below_minimum'))->toBe("Sensor 'Fuel level' reading (10 %) is below minimum threshold (20 %)")
            ->and($sensor->messageForTest(100, 'out_of_range'))->toBe("Sensor 'Fuel level' reading (100 %) is out of acceptable range (20-80 %)")
            ->and($sensor->messageForTest(50, 'normal'))->toBe("Sensor 'Fuel level' threshold violation detected");

        $history = $sensor->getReadingHistory(25, 6);

        expect($history['sensor_uuid'])->toBe('sensor-uuid')
            ->and($history['sensor_name'])->toBe('Fuel level')
            ->and($history['readings'])->toBe([])
            ->and($history['summary'])->toMatchArray([
                'count' => 0,
                'min'   => null,
                'max'   => null,
                'avg'   => null,
                'last'  => 44,
            ]);
    } finally {
        Carbon::setTestNow();
    }
});
