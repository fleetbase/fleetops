<?php

if (!function_exists('Fleetbase\FleetOps\Models\activity')) {
    eval('namespace Fleetbase\FleetOps\Models; function activity($logName = null) { return new class($logName) { public function __construct(public $logName) {} public function performedOn($subject) { return $this; } public function withProperties(array $properties) { return $this; } public function log(string $message) { return true; } }; }');
}

use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\Models\Alert;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

class FleetOpsDeviceEventModelDatabaseProbe
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

class FleetOpsDeviceEventQueryFake
{
    public array $calls = [];

    public function where(...$arguments): static
    {
        $this->calls[] = ['where', $arguments];

        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->calls[] = ['whereNull', $column];

        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->calls[] = ['whereNotNull', $column];

        return $this;
    }
}

class FleetOpsDeviceEventUpdatingFake extends DeviceEvent
{
    public array $updates = [];

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }

    public function alertMessageForTest(): string
    {
        return $this->generateAlertMessage();
    }
}

function fleetopsDeviceEventModelUseInMemoryConnection(bool $withTables = false): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsDeviceEventModelDatabaseProbe($connection));
    app()->instance('db.schema', $connection->getSchemaBuilder());

    if ($withTables) {
        $schema = $connection->getSchemaBuilder();
        $schema->create('device_events', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('device_uuid')->nullable();
            $table->text('payload')->nullable();
            $table->text('data')->nullable();
            $table->text('meta')->nullable();
            $table->string('event_type')->nullable();
            $table->string('severity')->nullable();
            $table->string('message')->nullable();
            $table->string('ident')->nullable();
            $table->string('provider')->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('alerts', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('type')->nullable();
            $table->string('severity')->nullable();
            $table->string('status')->nullable();
            $table->string('subject_type')->nullable();
            $table->string('subject_uuid')->nullable();
            $table->string('message')->nullable();
            $table->text('context')->nullable();
            $table->dateTime('triggered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    return $connection;
}

test('device event model exposes relation builders and logging options', function () {
    fleetopsDeviceEventModelUseInMemoryConnection();

    $event = new DeviceEvent();

    expect($event->getActivitylogOptions()->logOnlyDirty)->toBeTrue()
        ->and($event->company())->toBeInstanceOf(BelongsTo::class)
        ->and($event->company()->getRelated())->toBeInstanceOf(Company::class)
        ->and($event->device())->toBeInstanceOf(BelongsTo::class)
        ->and($event->device()->getRelated())->toBeInstanceOf(Device::class)
        ->and($event->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and($event->createdBy()->getRelated())->toBeInstanceOf(User::class);
});

test('device event accessors read related device and processing timestamps', function () {
    fleetopsDeviceEventModelUseInMemoryConnection();
    Carbon::setTestNow('2026-07-27 10:00:00');

    try {
        $telematic = new Telematic();
        $telematic->setRawAttributes(['name' => 'Afaqy unit', 'provider' => 'missing-provider'], true);

        $device = new Device();
        $device->setRawAttributes([
            'name'              => 'Trailer gateway',
            'device_id'         => 'device-001',
            'imei'              => '359881234567890',
            'serial_number'     => 'SN-42',
            'connection_status' => 'online',
            'status'            => 'active',
            'last_online_at'    => Carbon::parse('2026-07-27 09:55:00'),
            'telematic_uuid'    => 'telematic-uuid',
        ], true);
        $device->setRelation('telematic', $telematic);

        $event = new DeviceEvent();
        $event->forceFill([
            'ident'        => 'fallback-ident',
            'occurred_at'  => Carbon::parse('2026-07-27 09:50:00'),
            'processed_at' => Carbon::parse('2026-07-27 09:55:00'),
        ]);
        $event->setRelation('device', $device);

        $unprocessed = new DeviceEvent();
        $unprocessed->forceFill([
            'ident'      => 'fallback-ident',
            'created_at' => Carbon::parse('2026-07-27 09:40:00'),
        ]);

        expect($event->device_name)->toBe('Trailer gateway')
            ->and($event->device_id)->toBe('device-001')
            ->and($event->device_imei)->toBe('359881234567890')
            ->and($event->device_serial_number)->toBe('SN-42')
            ->and($event->device_connection_status)->toBe('online')
            ->and($event->device_status)->toBe('active')
            ->and($event->device_photo_url)->toBe('https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png')
            ->and($event->telematic_uuid)->toBe('telematic-uuid')
            ->and($event->telematic_name)->toBe('Afaqy unit')
            ->and($event->provider_descriptor)->toBe([])
            ->and($event->is_processed)->toBeTrue()
            ->and($event->age_minutes)->toBe(10)
            ->and($event->processing_delay_minutes)->toBe(5)
            ->and($unprocessed->device_id)->toBe('fallback-ident')
            ->and($unprocessed->device_name)->toBeNull()
            ->and($unprocessed->is_processed)->toBeFalse()
            ->and($unprocessed->age_minutes)->toBe(20)
            ->and($unprocessed->processing_delay_minutes)->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

test('device event scopes write expected query constraints', function () {
    Carbon::setTestNow('2026-07-27 10:00:00');

    try {
        $event = new DeviceEvent();
        $query = new FleetOpsDeviceEventQueryFake();

        expect($event->scopeByType($query, 'warning'))->toBe($query)
            ->and($event->scopeBySeverity($query, 'high'))->toBe($query)
            ->and($event->scopeProcessed($query))->toBe($query)
            ->and($event->scopeUnprocessed($query))->toBe($query)
            ->and($event->scopeRecent($query, 15))->toBe($query)
            ->and($event->scopeCritical($query))->toBe($query)
            ->and($query->calls[0])->toBe(['where', ['event_type', 'warning']])
            ->and($query->calls[1])->toBe(['where', ['severity', 'high']])
            ->and($query->calls[2])->toBe(['whereNotNull', 'processed_at'])
            ->and($query->calls[3])->toBe(['whereNull', 'processed_at'])
            ->and($query->calls[4][0])->toBe('where')
            ->and($query->calls[4][1][0])->toBe('occurred_at')
            ->and($query->calls[4][1][1])->toBe('>=')
            ->and($query->calls[5])->toBe(['where', ['severity', 'critical']]);
    } finally {
        Carbon::setTestNow();
    }
});

test('device event data helpers process state and alert decisions are stable', function () {
    Carbon::setTestNow('2026-07-27 10:00:00');

    try {
        $event = new FleetOpsDeviceEventUpdatingFake();
        $event->forceFill([
            'created_at' => Carbon::parse('2026-07-27 09:00:00'),
            'data'       => ['engine' => ['temperature' => 82]],
            'event_type' => 'heartbeat',
            'severity'   => 'medium',
            'message'    => 'nominal',
        ]);

        expect($event->getData('engine.temperature'))->toBe(82)
            ->and($event->getData('engine.rpm', 0))->toBe(0)
            ->and($event->setData('engine.rpm', 1500))->toBeTrue()
            ->and($event->updates[0]['data']['engine']['temperature'])->toBe(82)
            ->and($event->updates[0]['data']['engine']['rpm'])->toBe(1500)
            ->and($event->shouldTriggerAlert())->toBeFalse()
            ->and($event->markAsProcessed())->toBeTrue()
            ->and($event->updates[1]['processed_at']->toDateTimeString())->toBe('2026-07-27 10:00:00')
            ->and($event->markAsProcessed())->toBeFalse();

        expect((new DeviceEvent(['event_type' => 'warning', 'severity' => 'low']))->shouldTriggerAlert())->toBeTrue()
            ->and((new DeviceEvent(['event_type' => 'heartbeat', 'severity' => 'critical']))->shouldTriggerAlert())->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

test('device event alert messages cover event specific wording', function (string $type, string $expected) {
    $event = new FleetOpsDeviceEventUpdatingFake();
    $event->forceFill([
        'event_type' => $type,
        'message'    => 'Battery voltage dropped',
    ]);

    expect($event->alertMessageForTest())->toBe($expected);
})->with([
    'error'                => ['error', "Device 'Unknown Device' reported an error: Battery voltage dropped"],
    'warning'              => ['warning', "Device 'Unknown Device' issued a warning: Battery voltage dropped"],
    'critical failure'     => ['critical_failure', "Critical failure detected on device 'Unknown Device': Battery voltage dropped"],
    'security breach'      => ['security_breach', "Security breach detected on device 'Unknown Device': Battery voltage dropped"],
    'maintenance required' => ['maintenance_required', "Device 'Unknown Device' requires maintenance: Battery voltage dropped"],
    'threshold exceeded'   => ['threshold_exceeded', "Threshold exceeded on device 'Unknown Device': Battery voltage dropped"],
    'default'              => ['ignition', "Device 'Unknown Device' event (ignition): Battery voltage dropped"],
]);

test('device event creates and reuses alerts for alertable events', function () {
    Carbon::setTestNow('2026-07-27 10:00:00');

    try {
        fleetopsDeviceEventModelUseInMemoryConnection(true);

        $event = DeviceEvent::create([
            'uuid'         => 'event-uuid',
            'company_uuid' => 'company-uuid',
            'device_uuid'  => 'device-uuid',
            'event_type'   => 'error',
            'severity'     => 'high',
            'message'      => 'Fault code P0101',
            'data'         => ['code' => 'P0101'],
            'occurred_at'  => Carbon::parse('2026-07-27 09:58:00'),
        ]);
        $event->setRelation('device', new Device());

        $alert = $event->createAlert();

        expect($alert)->toBeInstanceOf(Alert::class)
            ->and($alert->type)->toBe('device_event')
            ->and($alert->severity)->toBe('high')
            ->and($alert->status)->toBe('open')
            ->and($alert->message)->toBe("Device 'Unknown Device' reported an error: Fault code P0101")
            ->and($alert->context['device_uuid'])->toBe('device-uuid')
            ->and($alert->context['event_type'])->toBe('error')
            ->and($event->createAlert()->is($alert))->toBeTrue()
            ->and((new DeviceEvent(['event_type' => 'heartbeat', 'severity' => 'low']))->createAlert())->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

test('device event correlation pattern checks and export payload are stable', function () {
    Carbon::setTestNow('2026-07-27 10:00:00');

    try {
        $connection = fleetopsDeviceEventModelUseInMemoryConnection(true);

        $connection->table('device_events')->insert([
            [
                'uuid'         => 'primary-event',
                'public_id'    => 'device_event_001',
                'device_uuid'  => 'device-uuid',
                'data'         => json_encode(['rpm' => 1100]),
                'event_type'   => 'warning',
                'severity'     => 'medium',
                'message'      => 'High idle',
                'occurred_at'  => '2026-07-27 09:55:00',
                'processed_at' => null,
                'created_at'   => '2026-07-27 09:54:00',
                'updated_at'   => '2026-07-27 09:54:00',
            ],
            [
                'uuid'         => 'near-event',
                'public_id'    => null,
                'device_uuid'  => 'device-uuid',
                'data'         => null,
                'event_type'   => 'warning',
                'severity'     => 'low',
                'message'      => 'Nearby',
                'occurred_at'  => '2026-07-27 09:57:00',
                'processed_at' => null,
                'created_at'   => '2026-07-27 09:57:00',
                'updated_at'   => '2026-07-27 09:57:00',
            ],
            [
                'uuid'         => 'far-event',
                'public_id'    => null,
                'device_uuid'  => 'device-uuid',
                'data'         => null,
                'event_type'   => 'warning',
                'severity'     => 'low',
                'message'      => 'Far away',
                'occurred_at'  => '2026-07-27 09:00:00',
                'processed_at' => null,
                'created_at'   => '2026-07-27 09:00:00',
                'updated_at'   => '2026-07-27 09:00:00',
            ],
        ]);

        $event = DeviceEvent::where('uuid', 'primary-event')->first();
        $event->forceFill(['processed_at' => Carbon::parse('2026-07-27 09:59:00')]);
        $event->setRelation('device', null);

        expect($event->getSeverityLevel())->toBe(2)
            ->and((new DeviceEvent(['severity' => 'critical']))->getSeverityLevel())->toBe(4)
            ->and((new DeviceEvent(['severity' => 'high']))->getSeverityLevel())->toBe(3)
            ->and((new DeviceEvent(['severity' => 'low']))->getSeverityLevel())->toBe(1)
            ->and((new DeviceEvent(['severity' => 'unknown']))->getSeverityLevel())->toBe(0)
            ->and($event->getCorrelatedEvents(5))->toHaveCount(1)
            ->and($event->isPartOfPattern(24, 3))->toBeTrue()
            ->and($event->isPartOfPattern(24, 4))->toBeFalse();

        $export = $event->exportForAnalysis();

        expect($export)->toMatchArray([
            'event_id'                 => 'device_event_001',
            'device_uuid'              => 'device-uuid',
            'device_name'              => null,
            'event_type'               => 'warning',
            'severity'                 => 'medium',
            'message'                  => 'High idle',
            'processing_delay_minutes' => 4,
            'data'                     => ['rpm' => 1100],
        ])
            ->and($export['occurred_at'])->toContain('2026-07-27T09:55:00')
            ->and($export['processed_at'])->toContain('2026-07-27T09:59:00')
            ->and($export['created_at'])->toContain('2026-07-27T09:54:00');
    } finally {
        Carbon::setTestNow();
    }
});

test('device event delegates position creation to the attached device subject', function () {
    $positionData = ['latitude' => 1.35, 'longitude' => 103.82];
    $attachable   = new class extends EloquentModel {
        public array $positions = [];

        public function createPosition(array $positionData)
        {
            $this->positions[] = $positionData;

            $position = new Position();
            $position->setRawAttributes(['positionData' => $positionData], true);

            return $position;
        }
    };

    $device = new Device();
    $device->setRelation('attachable', $attachable);

    $event = new DeviceEvent();
    $event->setRelation('device', $device);

    expect($event->createPosition())->toBeNull()
        ->and($event->createPosition($positionData)->getAttribute('positionData'))->toBe($positionData)
        ->and($attachable->positions)->toBe([$positionData]);

    $eventWithoutAttachable = new DeviceEvent();
    $eventWithoutAttachable->setRelation('device', new Device());

    expect($eventWithoutAttachable->createPosition($positionData))->toBeNull();
});
