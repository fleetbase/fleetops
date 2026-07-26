<?php

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    eval('namespace Illuminate\Foundation\Auth; class User extends \Illuminate\Database\Eloquent\Model {}');
}

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\now')) {
    eval('namespace Fleetbase\FleetOps\Models; function now() { return \Illuminate\Support\Carbon::now(); }');
}

use Fleetbase\FleetOps\Exceptions\CustomerUserConflictException;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\FleetOps\Models\PurchaseRate;
use Fleetbase\FleetOps\Models\Route;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Models\ServiceRateFee;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\VehicleDevice;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Traits\PayloadAccessors;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

function fleetopsModelAccessorsUseInMemoryRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
}

class FleetOpsCountingRelationFake
{
    public function __construct(private int $total, private int $online)
    {
    }

    public function where(string $column, mixed $value): self
    {
        expect($column)->toBe('online')
            ->and($value)->toBe(1);

        return new self($this->online, $this->online);
    }

    public function count(): int
    {
        return $this->total;
    }
}

class FleetOpsContactImportSaveFake extends Contact
{
    public bool $saved = false;

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }
}

class FleetOpsContactSyncUserFake extends Fleetbase\Models\User
{
    public array $updates = [];
    public bool $deleted  = false;

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return true;
    }

    public function delete()
    {
        $this->deleted = true;

        return true;
    }
}

class FleetOpsContactAccessorFake extends Contact
{
    public array $loadedMissing                   = [];
    public ?FleetOpsContactSyncUserFake $fakeUser = null;

    public function loadMissing($relations)
    {
        $this->loadedMissing[] = $relations;

        return $this;
    }

    public function getUser(): ?Fleetbase\Models\User
    {
        return $this->fakeUser;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return true;
    }
}

class FleetOpsDriverAccessorFake extends Driver
{
    public array $loadedMissing = [];
    public array $loaded        = [];
    public array $updates       = [];
    public bool $saved          = false;

    public function loadMissing($relations)
    {
        $this->loadedMissing[] = $relations;

        return $this;
    }

    public function load($relations)
    {
        $this->loaded[] = $relations;

        return $this;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return true;
    }

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }
}

class FleetOpsDriverOrderAccessorFake extends Order
{
    public array $loadedMissing = [];

    public function loadMissing($relations)
    {
        $this->loadedMissing[] = $relations;

        return $this;
    }
}

class FleetOpsFleetAccessorFake extends Fleet
{
    public function drivers()
    {
        return new FleetOpsCountingRelationFake(5, 2);
    }

    public function vehicles()
    {
        return new FleetOpsCountingRelationFake(7, 3);
    }
}

class FleetOpsTelematicQueryFake
{
    public array $calls = [];

    public function where(...$arguments): self
    {
        $this->calls[] = ['where', $arguments];

        return $this;
    }

    public function whereNull(...$arguments): self
    {
        $this->calls[] = ['whereNull', $arguments];

        return $this;
    }

    public function orWhere(...$arguments): self
    {
        $this->calls[] = ['orWhere', $arguments];

        return $this;
    }
}

class FleetOpsTelematicUpdatingFake extends Telematic
{
    public array $updated = [];

    public function update(array $attributes = [], array $options = [])
    {
        $this->updated = $attributes;
        $this->forceFill($attributes);

        return true;
    }
}

class FleetOpsModelAccessorTelematicRegistryFake
{
    public function __construct(private ?object $descriptor)
    {
    }

    public function findByKey(?string $key): ?object
    {
        if (!$key) {
            return null;
        }

        expect($key)->toBe('safee');

        return $this->descriptor;
    }
}

class FleetOpsUpdatingMaintenanceFake extends Maintenance
{
    public array $updates        = [];
    public array $dateAttributes = [];

    public function getAttribute($key)
    {
        if (array_key_exists($key, $this->dateAttributes)) {
            return $this->dateAttributes[$key] ? Carbon::parse($this->dateAttributes[$key]) : null;
        }

        return parent::getAttribute($key);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;

        return false;
    }
}

class FleetOpsUpdatingDeviceFake extends Device
{
    public array $updates            = [];
    public ?Carbon $lastOnlineAtFake = null;

    public function getAttribute($key)
    {
        if ($key === 'last_online_at') {
            return $this->lastOnlineAtFake;
        }

        return parent::getAttribute($key);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }
}

class FleetOpsLoadedVehicleDeviceFake extends VehicleDevice
{
    public function load($relations)
    {
        return $this;
    }
}

class FleetOpsTrackingNumberAccessorFake extends TrackingNumber
{
    public array $loaded = [];

    public function load($relations)
    {
        $this->loaded[] = $relations;

        return $this;
    }

    protected static function ownerHasStatusColumn(Fleetbase\Models\Model $owner): bool
    {
        return true;
    }
}

class FleetOpsTrackingNumberOwnerFake extends Fleetbase\Models\Model
{
    protected $fillable = ['status'];
    protected $table    = 'orders';
    public bool $saved  = false;

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }
}

class FleetOpsSavingOrderFake extends Order
{
    public bool $saved = false;

    public function getDateFormat()
    {
        return 'Y-m-d H:i:s';
    }

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }
}

class FleetOpsUpdatingManifestFake extends Manifest
{
    public array $updates = [];

    public function getDateFormat()
    {
        return 'Y-m-d H:i:s';
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }
}

class FleetOpsUpdatingManifestStopFake extends ManifestStop
{
    public array $updates          = [];
    public ?string $status         = null;
    public ?Carbon $actual_arrival = null;

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;

        foreach ($attributes as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }

        return true;
    }
}

class FleetOpsManifestStopManifestFake extends Manifest
{
    public int $autoCompleteChecks = 0;

    public function checkAndAutoComplete(): void
    {
        $this->autoCompleteChecks++;
    }
}

class FleetOpsManifestScopeQueryFake
{
    public array $calls = [];

    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        $this->calls[] = func_num_args() === 2
            ? ['where', $column, $operator]
            : ['where', $column, $operator, $value];

        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        $this->calls[] = ['whereIn', $column, $values];

        return $this;
    }

    public function whereNotIn(string $column, array $values): self
    {
        $this->calls[] = ['whereNotIn', $column, $values];

        return $this;
    }
}

class FleetOpsPayloadAccessorHostFake extends EloquentModel
{
    use PayloadAccessors;

    protected $guarded = [];

    public function payload(): BelongsTo
    {
        throw new RuntimeException('payload relation query should not be executed for loaded relation access');
    }
}

class FleetOpsLoadedPayloadFake extends Payload
{
    public ?string $uuidFake = null;

    public function getAttribute($key)
    {
        if ($key === 'uuid' && $this->uuidFake !== null) {
            return $this->uuidFake;
        }

        return parent::getAttribute($key);
    }

    public function load($relations)
    {
        return $this;
    }

    public function loadMissing($relations)
    {
        return $this;
    }
}

class FleetOpsPlainPlaceFake extends Place
{
    public function getAddressAttribute()
    {
        return $this->attributes['address'] ?? $this->attributes['name'] ?? $this->attributes['street1'] ?? null;
    }

    public function getAddressHtmlAttribute()
    {
        return $this->getAddressAttribute();
    }

    public function getCountryDataAttribute(): array
    {
        return [];
    }
}

class FleetOpsHydratablePlaceFake extends Place
{
    public bool $saved = false;

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }
}

class FleetOpsImportablePlaceFake extends Place
{
    public static ?string $lastGeocodedAddress = null;
    public bool $saved                         = false;

    public static function createFromGeocodingLookup(string $address, $saveInstance = false): ?Place
    {
        static::$lastGeocodedAddress = $address;

        return new static([
            'name'     => 'Geocoded Place',
            'street1'  => null,
            'location' => null,
        ]);
    }

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }
}

class FleetOpsLoadedServiceRateFake extends ServiceRate
{
    public function load($relations)
    {
        return $this;
    }

    public function loadMissing($relations)
    {
        return $this;
    }
}

class FleetOpsUpdatingWorkOrderFake extends WorkOrder
{
    public array $updates = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct();

        if ($attributes) {
            $this->setRawAttributes($attributes, true);
        }
    }

    public function getAttribute($key)
    {
        if (in_array($key, ['checklist', 'meta'], true)) {
            return $this->attributes[$key] ?? null;
        }

        if (in_array($key, ['opened_at', 'due_at', 'closed_at'], true)) {
            return isset($this->attributes[$key]) ? Carbon::parse($this->attributes[$key]) : null;
        }

        return parent::getAttribute($key);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return true;
    }
}

test('contact accessors imports notifications and customer identity helpers are stable', function () {
    fleetopsModelAccessorsUseInMemoryRelationConnection();

    $contact = new Contact([
        'name'  => 'Jane Contact',
        'email' => 'jane@example.com',
        'phone' => '+1 (555) 111-2222',
        'type'  => 'customer',
    ]);

    $contact->setRelation('devices', new Collection([
        (object) ['platform' => 'android', 'token' => 'android-token'],
        (object) ['platform' => 'ios', 'token' => 'ios-token'],
        (object) ['platform' => 'web', 'token' => 'web-token'],
    ]));
    $contact->setRelation('photo', (object) ['url' => 'https://cdn.example/avatar.png']);

    expect($contact->isCustomer())->toBeTrue()
        ->and($contact->is_customer)->toBeTrue()
        ->and($contact->getActivitylogOptions())->toBeInstanceOf(Spatie\Activitylog\LogOptions::class)
        ->and($contact->getSlugOptions())->toBeInstanceOf(Spatie\Sluggable\SlugOptions::class)
        ->and($contact->company())->toBeInstanceOf(BelongsTo::class)
        ->and($contact->anyUser())->toBeInstanceOf(BelongsTo::class)
        ->and($contact->user())->toBeInstanceOf(BelongsTo::class)
        ->and($contact->photo())->toBeInstanceOf(BelongsTo::class)
        ->and($contact->place())->toBeInstanceOf(BelongsTo::class)
        ->and($contact->devices())->toBeInstanceOf(HasMany::class)
        ->and($contact->places())->toBeInstanceOf(HasMany::class)
        ->and($contact->facilitatorOrders())->toBeInstanceOf(HasMany::class)
        ->and($contact->customerOrders())->toBeInstanceOf(HasMany::class)
        ->and($contact->files())->toBeInstanceOf(HasMany::class)
        ->and($contact->routeNotificationForFcm())->toBe(['android-token'])
        ->and($contact->routeNotificationForApn())->toBe([1 => 'ios-token'])
        ->and($contact->routeNotificationForTwilio())->toContain('555')
        ->and($contact->photo_url)->toBe('https://cdn.example/avatar.png');

    $imported = Contact::createFromImport([
        'full_name'     => 'Imported Person',
        'mobile_number' => '+1 555 333 4444',
        'email_address' => 'imported@example.com',
    ]);

    expect($imported)->toBeInstanceOf(Contact::class)
        ->and($imported->name)->toBe('Imported Person')
        ->and($imported->email)->toBe('imported@example.com')
        ->and($imported->type)->toBe('contact');

    $savedImport = FleetOpsContactImportSaveFake::createFromImport([
        'person'    => 'Saved Import',
        'telephone' => '555-777-8888',
    ], true);

    $noPhoto = new Contact();
    $noPhoto->setRelation('photo', null);

    $staffUser        = new Fleetbase\Models\User(['email' => 'staff@example.com', 'type' => 'admin']);
    $staffUser->phone = null;

    expect(fn () => $contact->assertCustomerUserCanBeAssigned($staffUser))
        ->toThrow(CustomerUserConflictException::class, 'existing staff user')
        ->and(Contact::customerUserConflictMessage($staffUser))->toContain('email')
        ->and(Contact::customerUserConflictMessage(new Fleetbase\Models\User(['phone' => '+15550001111'])))->toContain('phone number')
        ->and(Contact::customerUserConflictMessage())->toContain('user account')
        ->and($savedImport)->toBeInstanceOf(FleetOpsContactImportSaveFake::class)
        ->and($savedImport->saved)->toBeTrue()
        ->and($savedImport->name)->toBe('Saved Import')
        ->and($noPhoto->photo_url)->toBe('https://s3.ap-southeast-1.amazonaws.com/flb-assets/static/no-avatar.png');

    $customerUser = new Fleetbase\Models\User();
    $customerUser->setRawAttributes(['type' => 'customer'], true);
    expect($contact->assertCustomerUserCanBeAssigned($customerUser))->toBeNull();

    $contactOnly = new Contact(['type' => 'contact']);
    expect($contactOnly->isCustomer())->toBeFalse()
        ->and($contactOnly->is_customer)->toBeFalse();
});

test('contact user sync lookup and deletion guards are stable', function () {
    $user = new FleetOpsContactSyncUserFake();
    $user->setRawAttributes([
        'uuid'  => 'user-uuid',
        'type'  => 'customer',
        'name'  => 'Old Name',
        'email' => 'old@example.com',
        'phone' => '+15550000000',
    ], true);

    $contact           = new FleetOpsContactAccessorFake();
    $contact->fakeUser = $user;
    $contact->setRawAttributes([
        'uuid'      => 'contact-uuid',
        'user_uuid' => 'not-a-uuid',
        'type'      => 'customer',
        'name'      => 'Old Name',
        'email'     => 'old@example.com',
        'phone'     => '+15550000000',
        'timezone'  => 'UTC',
    ], true);

    $contact->name     = 'New Name';
    $contact->email    = 'new@example.com';
    $contact->phone    = '+15551112222';
    $contact->timezone = 'Asia/Singapore';

    expect($contact->syncWithUser())->toBeTrue()
        ->and($user->updates[0])->toBe([
            'name'     => 'New Name',
            'email'    => 'new@example.com',
            'phone'    => '+15551112222',
            'timezone' => 'Asia/Singapore',
        ])
        ->and($contact->getUser())->toBe($user)
        ->and($contact->hasUser())->toBeTrue()
        ->and($contact->doesntHaveUser())->toBeFalse();

    $deleteContact       = new FleetOpsContactAccessorFake();
    $deleteContact->type = 'customer';
    $deleteContact->setRelation('user', $user);

    expect($deleteContact->deleteUser())->toBeTrue()
        ->and($user->deleted)->toBeTrue()
        ->and($deleteContact->loadedMissing)->toBe(['user']);

    $mismatchedUser = new FleetOpsContactSyncUserFake();
    $mismatchedUser->setRawAttributes(['type' => 'admin'], true);

    $mismatchedContact       = new FleetOpsContactAccessorFake();
    $mismatchedContact->type = 'customer';
    $mismatchedContact->setRelation('user', $mismatchedUser);

    $emptyContact           = new FleetOpsContactAccessorFake();
    $emptyContact->fakeUser = null;
    $emptyContact->setRawAttributes(['user_uuid' => 'not-a-uuid'], true);

    expect($mismatchedContact->deleteUser())->toBeFalse()
        ->and($emptyContact->syncWithUser())->toBeFalse()
        ->and($emptyContact->getUser())->toBeNull()
        ->and($emptyContact->hasUser())->toBeFalse()
        ->and($emptyContact->doesntHaveUser())->toBeTrue();
});

test('device accessors connection state configuration and command guards are stable', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));

    $device = new FleetOpsUpdatingDeviceFake([
        'options' => [
            'supported_features' => ['lock', 'reboot'],
            'sample_rate'        => 30,
        ],
    ]);

    $device->setRelation('photo', (object) ['url' => 'https://cdn.example/device.png']);
    $device->setRelation('warranty', (object) ['name' => 'Extended warranty']);
    $device->setRelation('telematic', null);
    $device->setRelation('attachable', (object) ['display_name' => 'Truck 99']);

    expect($device->photo_url)->toBe('https://cdn.example/device.png')
        ->and($device->warranty_name)->toBe('Extended warranty')
        ->and($device->telematic_name)->toBeNull()
        ->and($device->attached_to_name)->toBe('Truck 99')
        ->and($device->is_online)->toBeFalse()
        ->and($device->connection_status)->toBe('never_connected')
        ->and($device->supportsFeature('lock'))->toBeTrue()
        ->and($device->supportsFeature('unlock'))->toBeFalse()
        ->and($device->getConfiguration())->toMatchArray(['sample_rate' => 30])
        ->and($device->sendCommand('reboot'))->toBeFalse();

    $device->lastOnlineAtFake = Carbon::parse('2026-01-01 11:55:00');
    expect($device->is_online)->toBeTrue()
        ->and($device->connection_status)->toBe('online');

    $device->lastOnlineAtFake = Carbon::parse('2026-01-01 11:30:00');
    expect($device->connection_status)->toBe('recently_offline');

    $device->lastOnlineAtFake = Carbon::parse('2026-01-01 01:00:00');
    expect($device->connection_status)->toBe('offline');

    $device->lastOnlineAtFake = Carbon::parse('2025-12-30 11:00:00');
    expect($device->connection_status)->toBe('long_offline')
        ->and($device->updateConfiguration(['sample_rate' => 60, 'mode' => 'eco']))->toBeTrue()
        ->and($device->updates)->toHaveCount(1)
        ->and($device->updates[0]['options'])->toMatchArray(['sample_rate' => 60, 'mode' => 'eco']);

    Carbon::setTestNow();
});

test('maintenance accessors lifecycle guards and import mapping are stable', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-10 12:00:00'));

    $maintenance = new FleetOpsUpdatingMaintenanceFake([
        'status'       => 'scheduled',
        'labor_cost'   => 1000,
        'parts_cost'   => 2500,
        'tax'          => 300,
        'total_cost'   => 3800,
        'line_items'   => [['label' => 'Oil']],
        'attachments'  => [],
        'meta'         => ['estimated_duration_hours' => 4],
    ]);
    $maintenance->dateAttributes = [
        'scheduled_at' => '2026-01-09 09:00:00',
        'started_at'   => '2026-01-09 08:00:00',
        'completed_at' => '2026-01-09 10:00:00',
    ];

    $maintenance->setRelation('maintainable', (object) ['display_name' => 'Truck 12']);
    $maintenance->setRelation('performedBy', (object) ['name' => 'Mechanic One']);
    $maintenance->setRelation('workOrder', (object) ['subject' => 'Quarterly service']);

    expect($maintenance->maintainable_name)->toBe('Truck 12')
        ->and($maintenance->performed_by_name)->toBe('Mechanic One')
        ->and($maintenance->work_order_subject)->toBe('Quarterly service')
        ->and($maintenance->duration_hours)->toBe(2.0)
        ->and($maintenance->is_overdue)->toBeTrue()
        ->and($maintenance->days_until_due)->toBeLessThan(0)
        ->and($maintenance->cost_breakdown)->toMatchArray(['subtotal' => 3500, 'total_cost' => 3800])
        ->and($maintenance->getEfficiencyRating())->toBe(100.0)
        ->and($maintenance->wasCompletedOnTime())->toBeFalse()
        ->and($maintenance->getCostPerHour())->toBe(1900.0);

    expect($maintenance->start())->toBeFalse()
        ->and($maintenance->complete(['labor_cost' => 1200, 'parts_cost' => 500, 'tax' => 100, 'notes' => 'Done']))->toBeFalse()
        ->and($maintenance->cancel('Duplicate ticket'))->toBeFalse()
        ->and($maintenance->addLineItem(['label' => 'Filter']))->toBeFalse()
        ->and($maintenance->removeLineItem(10))->toBeFalse()
        ->and($maintenance->addAttachment('file_uuid', 'Invoice'))->toBeFalse()
        ->and($maintenance->updates)->not->toBeEmpty();

    $completed                 = new FleetOpsUpdatingMaintenanceFake(['status' => 'completed']);
    $completed->dateAttributes = ['scheduled_at' => '2026-01-09'];
    expect($completed->is_overdue)->toBeFalse()
        ->and($completed->days_until_due)->toBeNull()
        ->and($completed->cancel())->toBeFalse();

    $imported = Maintenance::createFromImport([
        'maintenance_type'  => 'corrective',
        'priority'          => 'high',
        'description'       => 'Replace pads',
        'odometer_reading'  => '12345',
        'engine_hours'      => '88',
        'labor_cost'        => 1000,
        'parts_cost'        => 2000,
        'tax'               => 100,
        'total_cost'        => 3100,
        'currency'          => 'sgd',
    ]);

    expect($imported)->toBeInstanceOf(Maintenance::class)
        ->and($imported->type)->toBe('corrective')
        ->and($imported->priority)->toBe('high')
        ->and($imported->summary)->toBe('Replace pads')
        ->and($imported->currency)->toBe('SGD');

    Carbon::setTestNow();
});

test('waypoint model mirrors tracking number status accessors', function () {
    $waypoint = new Waypoint();
    $waypoint->setRelation('trackingNumber', (object) [
        'tracking_number'      => 'TRK-123',
        'last_status'          => 'Out for delivery',
        'last_status_code'     => 'out_for_delivery',
        'last_status_complete' => false,
    ]);

    expect($waypoint->getTrackingAttribute())->toBe('TRK-123')
        ->and($waypoint->getStatusAttribute())->toBe('Out for delivery')
        ->and($waypoint->getStatusCodeAttribute())->toBe('out_for_delivery')
        ->and($waypoint->getCompleteAttribute())->toBeFalse();

    $emptyWaypoint = new Waypoint();
    $emptyWaypoint->setRelation('trackingNumber', null);

    expect($emptyWaypoint->getTrackingAttribute())->toBeNull()
        ->and($emptyWaypoint->getStatusAttribute())->toBeNull()
        ->and($emptyWaypoint->getStatusCodeAttribute())->toBeNull()
        ->and($emptyWaypoint->getCompleteAttribute())->toBeNull();
});

test('waypoint model exposes relationship contracts without resolving records', function () {
    fleetopsModelAccessorsUseInMemoryRelationConnection();

    $waypoint = new Waypoint();
    $waypoint->setRawAttributes([
        'uuid'         => 'waypoint-uuid',
        'payload_uuid' => 'payload-uuid',
        'place_uuid'   => 'place-uuid',
    ], true);

    expect($waypoint->place())->toBeInstanceOf(BelongsTo::class)
        ->and($waypoint->trackingNumber())->toBeInstanceOf(BelongsTo::class)
        ->and($waypoint->payload())->toBeInstanceOf(BelongsTo::class)
        ->and($waypoint->company())->toBeInstanceOf(BelongsTo::class)
        ->and($waypoint->proofs())->toBeInstanceOf(HasMany::class)
        ->and($waypoint->entities())->toBeInstanceOf(HasMany::class)
        ->and($waypoint->customer())->toBeInstanceOf(MorphTo::class);
});

test('waypoint model resolves loaded place and rejects unscoped place lookup', function () {
    fleetopsModelAccessorsUseInMemoryRelationConnection();

    $place = new Place();
    $place->setRawAttributes(['uuid' => 'place-uuid'], true);

    $waypoint = new Waypoint();
    $waypoint->setRawAttributes(['place_uuid' => 'place-uuid'], true);
    $waypoint->setRelation('place', $place);

    expect($waypoint->getPlace())->toBe($place)
        ->and(fn () => Waypoint::findByPlace('place_public', new Order()))
        ->toThrow(InvalidArgumentException::class, 'Missing payload UUID for lookup.');
});

test('service quote model exposes pure helpers and safe request resolution defaults', function () {
    $quote = new ServiceQuote();
    $quote->setRawAttributes([
        'public_id'              => 'quote_public',
        'amount'                 => 12345,
        'currency'               => 'SGD',
        'integrated_vendor_uuid' => null,
        'meta'                   => [],
    ], true);
    $quote->setRelation('serviceRate', (object) ['service_name' => 'Same Day']);

    $vendorQuote = new ServiceQuote();
    $vendorQuote->setRawAttributes([
        'integrated_vendor_uuid' => 'integrated-vendor-uuid',
        'meta'                   => [],
    ], true);

    $metaVendorQuote = new ServiceQuote();
    $metaVendorQuote->setRawAttributes(['meta' => ['from_integrated_vendor' => true]], true);

    $customNames               = new ServiceQuote();
    $customNames->pluralName   = 'offers';
    $customNames->singularName = 'offer';

    $payloadKey             = new ServiceQuote();
    $payloadKey->payloadKey = 'rate_quote';

    expect($quote->getServiceRateNameAttribute())->toBe('Same Day')
        ->and($quote->fromIntegratedVendor())->toBeFalse()
        ->and($vendorQuote->fromIntegratedVendor())->toBeTrue()
        ->and($metaVendorQuote->fromIntegratedVendor())->toBeTrue()
        ->and(ServiceQuote::resolveFromRequest(new class extends Request {
            public function or(array $keys, mixed $default = null): mixed
            {
                return $default;
            }
        }))->toBeNull()
        ->and(ServiceQuote::resolveFromRequest(new class extends Request {
            public function or(array $keys, mixed $default = null): mixed
            {
                return '';
            }
        }))->toBeNull()
        ->and($customNames->getPluralName())->toBe('offers')
        ->and($customNames->getSingularName())->toBe('offer')
        ->and($payloadKey->getPluralName())->toBe('rate_quotes')
        ->and($payloadKey->getSingularName())->toBe('rate_quote')
        ->and((new ServiceQuote())->getPluralName())->toBe('service_quotes')
        ->and((new ServiceQuote())->getSingularName())->toBe('service_quote');
});

test('issue model mutators accessors and import defaults are stable', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 08:45:00'));
    session(['company' => 'company-issue']);

    $issue         = new Issue();
    $issue->title  = null;
    $issue->status = 'In Progress';
    $issue->setRelation('driver', (object) ['name' => 'Driver One']);
    $issue->setRelation('vehicle', (object) ['display_name' => 'Truck 42', 'public_id' => 'vehicle_public']);
    $issue->setRelation('reporter', (object) ['name' => 'Reporter One', 'public_id' => 'reporter_public']);
    $issue->setRelation('assignee', (object) ['name' => 'Assignee One', 'public_id' => 'assignee_public']);

    $blankStatus         = new Issue();
    $blankStatus->status = '';

    $trimmedTitle        = new Issue();
    $trimmedTitle->title = '  Loose bumper  ';

    expect($issue->getAttributes()['title'])->toBe('Issue reported on 26 Jul 26, 08:45')
        ->and($issue->status)->toBe('in-progress')
        ->and($blankStatus->status)->toBe('pending')
        ->and($trimmedTitle->getAttributes()['title'])->toBe('Loose bumper')
        ->and($issue->driver_name)->toBe('Driver One')
        ->and($issue->vehicle_name)->toBe('Truck 42')
        ->and($issue->getVehicleIdAttribute())->toBe('vehicle_public')
        ->and($issue->reporter_name)->toBe('Reporter One')
        ->and($issue->getReporterIdAttribute())->toBe('reporter_public')
        ->and($issue->assignee_name)->toBe('Assignee One')
        ->and($issue->getAssigneeIdAttribute())->toBe('assignee_public')
        ->and($issue->getActivitylogOptions())->toBeInstanceOf(Spatie\Activitylog\LogOptions::class);

    Carbon::setTestNow();
});

test('entity model value accessors money mutators and customer assignment are stable', function () {
    $entity = new Entity([
        'type'            => ' Fragile Parcel ',
        'price'           => '$12.99',
        'sale_price'      => '10.50',
        'declared_value'  => 'SGD 42.75',
        'length'          => 10,
        'width'           => 20,
        'height'          => 30,
        'dimensions_unit' => 'cm',
        'weight'          => 2500,
        'weight_unit'     => 'g',
    ]);
    $entity->setRelation('photo', (object) ['url' => 'https://cdn.test/entity.png']);
    $entity->setRelation('trackingNumber', (object) [
        'tracking_number' => 'TRK-ENTITY',
        'last_status'     => 'Packed',
    ]);

    $vendorCustomer = new Vendor();
    $vendorCustomer->setRawAttributes(['uuid' => 'vendor-uuid'], true);

    $contactCustomer = new Contact();
    $contactCustomer->setRawAttributes(['uuid' => 'contact-uuid'], true);

    $vendorEntity = new Entity();
    $vendorEntity->setCustomer($vendorCustomer);

    $contactEntity = new Entity();
    $contactEntity->setCustomer($contactCustomer);

    $defaultPhoto = new Entity();
    $defaultPhoto->setRelation('photo', null);

    expect($entity->type)->toBe('fragile_parcel')
        ->and($entity->price)->toBe(1299)
        ->and($entity->sale_price)->toBe(1050)
        ->and($entity->declared_value)->toBe(4275)
        ->and($entity->photo_url)->toBe('https://cdn.test/entity.png')
        ->and($defaultPhoto->photo_url)->toBe('https://s3.ap-southeast-1.amazonaws.com/flb-assets/static/parcels/medium.png')
        ->and($entity->tracking)->toBe('TRK-ENTITY')
        ->and($entity->status)->toBe('Packed')
        ->and($entity->length_unit->getOriginalValue())->toBe(10)
        ->and($entity->width_unit->getOriginalValue())->toBe(20)
        ->and($entity->height_unit->getOriginalValue())->toBe(30)
        ->and($entity->mass_unit->getOriginalValue())->toBe(2500)
        ->and($vendorEntity->customer_uuid)->toBe('vendor-uuid')
        ->and($vendorEntity->customer_is_vendor)->toBeTrue()
        ->and($vendorEntity->customer_is_contact)->toBeFalse()
        ->and($contactEntity->customer_uuid)->toBe('contact-uuid')
        ->and($contactEntity->customer_is_contact)->toBeTrue()
        ->and($contactEntity->customer_is_vendor)->toBeFalse();
});

test('route position and vehicle device accessors use loaded relation data', function () {
    $payload = new Payload(['public_id' => 'payload_public']);
    $driver  = new Vehicle(['display_name' => 'Driver vehicle']);
    $order   = (object) [
        'status'         => 'dispatched',
        'public_id'      => 'order_public',
        'internal_id'    => 'ORD-1',
        'dispatched_at'  => '2026-01-01 10:00:00',
        'payload'        => $payload,
        'driverAssigned' => $driver,
    ];

    $route = new Route();
    $route->setRelation('order', $order);

    expect($route->payload)->toBe($payload)
        ->and($route->driver)->toBe($driver)
        ->and($route->order_status)->toBe('dispatched')
        ->and($route->order_public_id)->toBe('order_public')
        ->and($route->order_internal_id)->toBe('ORD-1')
        ->and($route->order_dispatched_at)->toBe('2026-01-01 10:00:00');

    $position = new Position();
    expect($position->latitude)->toBe(0.0)
        ->and($position->longitude)->toBe(0.0);

    $position->coordinates = new Point(1.3521, 103.8198);
    expect($position->latitude)->toBe(1.3521)
        ->and($position->longitude)->toBe(103.8198);

    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-uuid'], true);

    $device = new FleetOpsLoadedVehicleDeviceFake();
    $device->setRelation('vehicle', $vehicle);

    $callbackVehicle = null;
    expect($device->getVehicle(function (Vehicle $resolved) use (&$callbackVehicle) {
        $callbackVehicle = $resolved;
    }))->toBe($vehicle)
        ->and($callbackVehicle)->toBe($vehicle);
});

test('fuel report accessors mutators and meta helpers are stable', function () {
    $report = new FuelReport([
        'amount' => '$45.67',
        'meta'   => [
            'source'                         => 'provider',
            'provider'                       => 'fuelx',
            'fuel_provider_transaction_uuid' => 'transaction_uuid',
        ],
    ]);

    $report->setRelation('driver', (object) ['name' => 'Driver One']);
    $report->setRelation('vehicle', (object) ['display_name' => 'Truck 8']);
    $report->setRelation('reportedBy', (object) ['name' => 'Dispatcher One']);

    expect($report->amount)->toBe(4567)
        ->and($report->driver_name)->toBe('Driver One')
        ->and($report->vehicle_name)->toBe('Truck 8')
        ->and($report->reporter_name)->toBe('Dispatcher One')
        ->and($report->source)->toBe('provider')
        ->and($report->provider)->toBe('fuelx')
        ->and($report->fuel_provider_transaction_uuid)->toBe('transaction_uuid');

    $container     = app();
    $hadDbBinding  = $container->bound('db');
    $originalDb    = $hadDbBinding ? $container->make('db') : null;

    try {
        $container->instance('db', new class {
            public function raw($value): Illuminate\Database\Query\Expression
            {
                return new Illuminate\Database\Query\Expression($value);
            }
        });

        $imported = FuelReport::createFromImport([
            'fuel_report'     => 'Imported fill-up',
            'usage'           => '12034',
            'cost'            => '$88.90',
            'amount_currency' => 'sgd',
            'gas_volume'      => '45.5',
            'gas_unit'        => 'gal',
            'fuel_status'     => 'approved',
            'lat'             => 1.3521,
            'lng'             => 103.8198,
        ]);
    } finally {
        if ($hadDbBinding) {
            $container->instance('db', $originalDb);
        } else {
            $container->forgetInstance('db');
        }
    }

    expect($imported)->toBeInstanceOf(FuelReport::class)
        ->and($imported->company_uuid)->toBe(session('company'))
        ->and($imported->report)->toBe('Imported fill-up')
        ->and($imported->odometer)->toBe('12034')
        ->and($imported->amount)->toBe(8890)
        ->and($imported->currency)->toBe('sgd')
        ->and($imported->volume)->toBe('45.5')
        ->and($imported->metric_unit)->toBe('gal')
        ->and($imported->status)->toBe('approved')
        ->and($imported->location->getValue(new Illuminate\Database\Query\Grammars\Grammar()))->toContain('POINT');
});

test('fuel provider transaction accessors expose related names and station points', function () {
    $transaction = new FuelProviderTransaction([
        'station_latitude'  => 1.3521,
        'station_longitude' => 103.8198,
    ]);
    $transaction->setRelation('vehicle', (object) ['display_name' => 'Truck 8']);
    $transaction->setRelation('driver', (object) ['name' => 'Driver One']);
    $transaction->setRelation('fuelReport', (object) ['public_id' => 'fuel_report_123']);

    expect($transaction->vehicle_name)->toBe('Truck 8')
        ->and($transaction->driver_name)->toBe('Driver One')
        ->and($transaction->fuel_report_id)->toBe('fuel_report_123')
        ->and($transaction->station_location)->toBeInstanceOf(Point::class)
        ->and($transaction->station_location->getLat())->toBe(1.3521)
        ->and($transaction->station_location->getLng())->toBe(103.8198);

    $transaction->station_latitude  = null;
    $transaction->station_longitude = 103.8198;

    expect($transaction->station_location)->toBeNull();
});

test('vendor accessors mutators options notifications and import mapping are stable', function () {
    $vendor = new Vendor([
        'name'   => 'Vendor One',
        'phone'  => '+1 (555) 444-3333',
        'type'   => null,
        'status' => null,
    ]);

    $vendor->setRelation('logo', (object) ['url' => 'https://cdn.example/vendor.png']);
    $vendor->setRelation('place', (object) [
        'address_html' => '1 Vendor Way',
        'street1'      => '1 Vendor Way',
    ]);

    expect($vendor->type)->toBe('vendor')
        ->and($vendor->status)->toBe('active')
        ->and($vendor->logo_url)->toBe('https://cdn.example/vendor.png')
        ->and($vendor->address)->toBe('1 Vendor Way')
        ->and($vendor->address_street)->toBe('1 Vendor Way')
        ->and($vendor->routeNotificationForTwilio())->toContain('555');

    $vendor->type   = null;
    $vendor->status = null;

    expect($vendor->type)->toBe('vendor')
        ->and($vendor->status)->toBe('active');

    $slugOptions = $vendor->getSlugOptions();
    $logOptions  = $vendor->getActivitylogOptions();

    expect($slugOptions->generateSlugFrom)->toBe(['name'])
        ->and($slugOptions->slugField)->toBe('slug')
        ->and($logOptions->logAttributes)->toContain('name', 'email', 'company_uuid')
        ->and($logOptions->logOnlyDirty)->toBeTrue();

    $imported = Vendor::createFromImport([
        'full_name'     => 'Imported Vendor',
        'mobile_number' => '+1 555 222 1111',
        'email_address' => 'vendor@example.com',
        'website_url'   => 'https://vendor.example',
        'country_name'  => 'United States',
    ]);

    expect($imported)->toBeInstanceOf(Vendor::class)
        ->and($imported->name)->toBe('Imported Vendor')
        ->and($imported->phone)->toContain('555')
        ->and($imported->email)->toBe('vendor@example.com')
        ->and($imported->type)->toBe('vendor')
        ->and($imported->status)->toBe('active')
        ->and($imported->country)->toBe('US');
});

test('service rate accessors flags fee normalization and quote helpers are stable', function () {
    fleetopsModelAccessorsUseInMemoryRelationConnection();

    $rate = new ServiceRate([
        'service_name'                  => 'Express',
        'rate_calculation_method'       => 'fixed_meter',
        'has_peak_hours_fee'            => true,
        'peak_hours_calculation_method' => 'flat',
        'peak_hours_start'              => '00:00',
        'peak_hours_end'                => '23:59',
        'has_cod_fee'                   => true,
        'cod_calculation_method'        => 'percentage',
        'base_fee'                      => 500,
        'currency'                      => 'USD',
    ]);

    $rate->setRelation('serviceArea', (object) ['name' => 'Central']);
    $rate->setRelation('zone', (object) ['name' => 'Zone A']);

    expect($rate->rateFees())->toBeInstanceOf(HasMany::class)
        ->and($rate->parcelFees())->toBeInstanceOf(HasMany::class)
        ->and($rate->orderConfig())->toBeInstanceOf(BelongsTo::class)
        ->and($rate->serviceArea())->toBeInstanceOf(BelongsTo::class)
        ->and($rate->zone())->toBeInstanceOf(BelongsTo::class)
        ->and($rate->service_area_name)->toBe('Central')
        ->and($rate->zone_name)->toBe('Zone A')
        ->and($rate->isRateCalculationMethod('fixed_meter'))->toBeTrue()
        ->and($rate->isRateCalculationMethod(['per_meter', 'fixed_meter']))->toBeTrue()
        ->and($rate->isFixedMeter())->toBeTrue()
        ->and($rate->isFixedRate())->toBeTrue()
        ->and($rate->isPerMeter())->toBeFalse()
        ->and($rate->isMultiZoneDistance())->toBeFalse()
        ->and($rate->isPerDrop())->toBeFalse()
        ->and($rate->isAlgorithm())->toBeFalse()
        ->and($rate->isParcelService())->toBeFalse()
        ->and($rate->hasPeakHoursFee())->toBeTrue()
        ->and($rate->isWithinPeakHours())->toBeTrue()
        ->and($rate->hasPeakHoursFlatFee())->toBeTrue()
        ->and($rate->hasPeakHoursPercentageFee())->toBeFalse()
        ->and($rate->hasCodFee())->toBeTrue()
        ->and($rate->hasCodFlatFee())->toBeFalse()
        ->and($rate->hasCodPercentageFee())->toBeTrue();

    $rate->setEstimatedDaysAttribute(null);
    expect($rate->estimated_days)->toBe(0);

    expect($rate->normalizeServiceRateFeePayload([
        'uuid'          => 'fee_uuid',
        'service_area'  => ['uuid' => 'area_uuid'],
        'zone'          => ['uuid' => 'zone_uuid'],
        'is_fallback'   => true,
        'label'         => 'Fallback',
        'ignored_field' => 'ignored',
    ]))->toMatchArray([
        'uuid'              => 'fee_uuid',
        'service_area_uuid' => null,
        'zone_uuid'         => null,
        'label'             => 'Fallback',
        'is_fallback'       => true,
    ]);

    expect($rate->normalizeServiceRateFeePayload('bad payload'))->toBeNull();
});

test('service rate alternate calculation branches and relation predicates are stable', function () {
    fleetopsModelAccessorsUseInMemoryRelationConnection();

    $area = new ServiceArea();
    $area->setRawAttributes(['name' => 'Central Area'], true);
    $zone = new Zone();
    $zone->setRawAttributes(['name' => 'Downtown Zone'], true);

    $relationRate = new FleetOpsLoadedServiceRateFake([
        'rate_calculation_method'       => 'algorithm',
        'has_peak_hours_fee'            => false,
        'peak_hours_calculation_method' => 'percentage',
        'has_cod_fee'                   => false,
        'cod_calculation_method'        => 'flat',
        'base_fee'                      => 100,
        'currency'                      => 'USD',
    ]);
    $relationRate->setRelation('serviceArea', $area);
    $relationRate->setRelation('zone', $zone);

    expect($relationRate->hasServiceArea())->toBeTrue()
        ->and($relationRate->hasZone())->toBeTrue()
        ->and($relationRate->isAlgorithm())->toBeTrue()
        ->and($relationRate->isRateCalculationMethod(['fixed_meter', 'algorithm']))->toBeTrue()
        ->and($relationRate->hasPeakHoursFee())->toBeFalse()
        ->and($relationRate->hasPeakHoursFlatFee())->toBeFalse()
        ->and($relationRate->hasPeakHoursPercentageFee())->toBeTrue()
        ->and($relationRate->hasCodFee())->toBeFalse()
        ->and($relationRate->hasCodFlatFee())->toBeTrue()
        ->and($relationRate->hasCodPercentageFee())->toBeFalse();

    $fixedRate = new FleetOpsLoadedServiceRateFake([
        'rate_calculation_method' => 'fixed_rate',
        'base_fee'                => 100,
        'currency'                => 'USD',
    ]);
    $fixedFee = new ServiceRateFee(['distance' => 5, 'fee' => 250]);
    $fixedRate->setRelation('rateFees', collect([$fixedFee]));

    [$fixedTotal, $fixedLines] = $fixedRate->quoteFromPreliminaryData([], [], 3000, 0);

    $dropRate = new FleetOpsLoadedServiceRateFake([
        'rate_calculation_method' => 'per_drop',
        'base_fee'                => 100,
        'currency'                => 'USD',
    ]);
    $dropFee = new ServiceRateFee(['min' => 3, 'max' => 5, 'fee' => 175]);
    $dropRate->setRelation('rateFees', collect([$dropFee]));

    [$dropTotal, $dropLines] = $dropRate->quoteFromPreliminaryData([], [new Place(), new Place(), new Place()], 0, 0);

    $algorithmRate = new FleetOpsLoadedServiceRateFake([
        'rate_calculation_method' => 'algo',
        'algorithm'               => '{base_fee} + {distance_km} + {stops}',
        'base_fee'                => 100,
        'currency'                => 'USD',
    ]);

    [$algorithmTotal, $algorithmLines] = $algorithmRate->quoteFromPreliminaryData([], [new Place(), new Place(), new Place()], 2500, 0, false, 2);

    $reflection       = new ReflectionClass(ServiceRate::class);
    $normalizer       = $reflection->getMethod('normalizeDistanceForUnit');
    $weightNormalizer = $reflection->getMethod('normalizeEntityWeightToKilograms');
    $multiZoneQuote   = $reflection->getMethod('quoteMultiZoneDistance');
    $multiZoneCalc    = $reflection->getMethod('calculateMultiZoneDistances');
    $geometryReader   = $reflection->getMethod('readRateRuleGeometry');
    $placePoint       = $reflection->getMethod('getLngLatFromPlace');

    $emptyMultiZoneRate = new FleetOpsLoadedServiceRateFake([
        'rate_calculation_method' => 'multi_zone_distance',
        'base_fee'                => 100,
        'currency'                => 'USD',
    ]);
    $emptyMultiZoneRate->setRelation('rateFees', collect());

    [$emptyMultiZoneTotal, $emptyMultiZoneLines] = $multiZoneQuote->invoke($emptyMultiZoneRate, [], 1500);

    $fallbackFee = new ServiceRateFee([
        'uuid'          => 'fallback-fee',
        'is_fallback'   => true,
        'priority'      => 10,
        'distance_unit' => 'km',
        'fee'           => 2,
    ]);
    $fallbackMultiZoneRate = new FleetOpsLoadedServiceRateFake([
        'rate_calculation_method' => 'multi_zone_distance',
        'base_fee'                => 100,
        'currency'                => 'USD',
    ]);
    $fallbackMultiZoneRate->setRelation('rateFees', collect([$fallbackFee]));

    [$fallbackMultiZoneTotal, $fallbackMultiZoneLines] = $multiZoneQuote->invoke($fallbackMultiZoneRate, [], 1500);
    $fallbackDistances                                 = $multiZoneCalc->invoke($fallbackMultiZoneRate, [new Place()], collect(), $fallbackFee, 1500);
    $invalidGeometry                                   = $geometryReader->invoke($fallbackMultiZoneRate, new ServiceRateFee(['zone' => ['border' => 'not-json']]), new Brick\Geo\IO\GeoJSONReader());

    expect($fixedRate->isFixedMeter())->toBeTrue()
        ->and($fixedRate->isFixedRate())->toBeTrue()
        ->and($fixedTotal)->toBe(350)
        ->and($fixedLines)->toHaveCount(2)
        ->and($dropRate->isPerDrop())->toBeTrue()
        ->and($dropTotal)->toBe(275)
        ->and($dropLines)->toHaveCount(2)
        ->and($algorithmRate->isAlgorithm())->toBeTrue()
        ->and($algorithmTotal)->toBe(206)
        ->and($algorithmLines)->toHaveCount(2)
        ->and(round($normalizer->invoke($relationRate, 3.048, 'ft'), 1))->toBe(9.8)
        ->and(round($normalizer->invoke($relationRate, 9.144, 'yd'), 1))->toBe(9.8)
        ->and($normalizer->invoke($relationRate, 123, 'm'))->toBe(123.0)
        ->and($normalizer->invoke($relationRate, null, 'km'))->toBe(0.0)
        ->and($weightNormalizer->invoke($relationRate, ['weight' => null]))->toBe(0.0)
        ->and($weightNormalizer->invoke($relationRate, ['weight' => 'heavy']))->toBe(0.0)
        ->and($weightNormalizer->invoke($relationRate, ['weight' => 2, 'weight_unit' => 'tonnes']))->toBe(2000.0)
        ->and($weightNormalizer->invoke($relationRate, ['weight' => 3, 'weight_unit' => 'kg']))->toBe(3.0)
        ->and($emptyMultiZoneRate->isMultiZoneDistance())->toBeTrue()
        ->and($emptyMultiZoneTotal)->toBe(0)
        ->and($emptyMultiZoneLines)->toHaveCount(0)
        ->and($fallbackMultiZoneTotal)->toBe(3)
        ->and($fallbackMultiZoneLines)->toHaveCount(1)
        ->and($fallbackMultiZoneLines->first()['code'])->toBe('MULTI_ZONE_DISTANCE_FEE')
        ->and($fallbackDistances[0]['rule'])->toBe($fallbackFee)
        ->and($fallbackDistances[0]['distance_m'])->toBe(1500.0)
        ->and($invalidGeometry)->toBeNull()
        ->and($placePoint->invoke($relationRate, null))->toBeNull();
});

test('service rate multi zone geometry helpers match rule and fallback distances', function () {
    fleetopsModelAccessorsUseInMemoryRelationConnection();

    $rate = new FleetOpsLoadedServiceRateFake([
        'rate_calculation_method' => 'multi_zone_distance',
        'base_fee'                => 0,
        'currency'                => 'USD',
    ]);

    $zonePolygon = json_encode([
        'type'        => 'Polygon',
        'coordinates' => [[
            [103.70, 1.20],
            [103.95, 1.20],
            [103.95, 1.45],
            [103.70, 1.45],
            [103.70, 1.20],
        ]],
    ]);
    $serviceAreaPolygon = [
        'type'        => 'Polygon',
        'coordinates' => [[
            [103.00, 1.00],
            [104.20, 1.00],
            [104.20, 1.80],
            [103.00, 1.80],
            [103.00, 1.00],
        ]],
    ];

    $zoneRule = new ServiceRateFee([
        'uuid'          => 'zone-rule',
        'label'         => 'Downtown',
        'priority'      => 20,
        'is_fallback'   => false,
        'distance_unit' => 'km',
        'fee'           => 3,
    ]);
    $zoneRule->setRelation('zone', (object) [
        'name'   => 'Downtown',
        'border' => $zonePolygon,
    ]);

    $serviceAreaRule = new ServiceRateFee([
        'uuid'          => 'area-rule',
        'label'         => 'Metro distance charge',
        'priority'      => 10,
        'is_fallback'   => false,
        'distance_unit' => 'km',
        'fee'           => 2,
    ]);
    $serviceAreaRule->setRelation('serviceArea', (object) [
        'name'   => 'Metro',
        'border' => $serviceAreaPolygon,
    ]);

    $fallbackRule = new ServiceRateFee([
        'uuid'          => 'fallback-rule',
        'priority'      => 1,
        'is_fallback'   => true,
        'distance_unit' => 'km',
        'fee'           => 5,
    ]);

    $rate->setRelation('rateFees', collect([$fallbackRule, $serviceAreaRule, $zoneRule]));

    $insideStart = new Place(['location' => new Point(1.30, 103.80)]);
    $outsideEnd  = new Place(['location' => new Point(2.10, 104.80)]);

    $reflection      = new ReflectionClass(ServiceRate::class);
    $multiZoneQuote  = $reflection->getMethod('quoteMultiZoneDistance');
    $multiZoneCalc   = $reflection->getMethod('calculateMultiZoneDistances');
    $geometryReader  = $reflection->getMethod('readRateRuleGeometry');
    $placePoint      = $reflection->getMethod('getLngLatFromPlace');

    $reader        = new Brick\Geo\IO\GeoJSONReader();
    $zoneGeometry  = $geometryReader->invoke($rate, $zoneRule, $reader);
    $areaGeometry  = $geometryReader->invoke($rate, $serviceAreaRule, $reader);
    $emptyGeometry = $geometryReader->invoke($rate, new ServiceRateFee(), $reader);

    $rate->setRelation('rateFees', collect([$fallbackRule]));
    [$fallbackTotal, $fallbackLines] = $multiZoneQuote->invoke($rate, [$insideStart, $outsideEnd], 2400);

    $fallbackDistances = $multiZoneCalc->invoke($rate, [$insideStart, $outsideEnd], collect(), $fallbackRule, 2400);

    expect($zoneGeometry)->not->toBeNull()
        ->and($areaGeometry)->not->toBeNull()
        ->and($emptyGeometry)->toBeNull()
        ->and($fallbackTotal)->toBe(12)
        ->and($fallbackLines)->toHaveCount(1)
        ->and($fallbackLines->first()['details'])->toContain('Out-of-zone distance charge')
        ->and($fallbackDistances[0]['rule'])->toBe($fallbackRule)
        ->and($fallbackDistances[0]['distance_m'])->toBe(2400.0)
        ->and($placePoint->invoke($rate, $insideStart))->toBe(['lat' => 1.3, 'lng' => 103.8]);
});

test('fleet accessors expose photo fallback and online asset counts', function () {
    $fleet = new FleetOpsFleetAccessorFake();
    $fleet->setRelation('photo', null);

    expect($fleet->photo_url)->toBe('https://s3.ap-northeast-2.amazonaws.com/fleetbase/public/default-fleet.png')
        ->and($fleet->drivers_count)->toBe(5)
        ->and($fleet->drivers_online_count)->toBe(2)
        ->and($fleet->vehicles_count)->toBe(7)
        ->and($fleet->vehicles_online_count)->toBe(3)
        ->and($fleet->getActivitylogOptions()->logAttributes)->toBe(['name', 'task', 'service_area_uuid', 'zone_uuid'])
        ->and($fleet->getSlugOptions()->slugField)->toBe('slug');
});

test('driver accessors notification routes and assignment helpers are stable', function () {
    $driver = new FleetOpsDriverAccessorFake();
    $driver->setRawAttributes([
        'uuid'                   => 'driver-uuid',
        'public_id'              => 'driver_public',
        'company_uuid'           => 'company-uuid',
        'heading'                => 90,
        'vehicle_uuid'           => null,
        'drivers_license_number' => 'DL-123',
    ], true);
    $driver->setRelation('user', (object) [
        'avatar'    => 'avatar-object',
        'avatarUrl' => 'https://cdn.test/driver.png',
        'name'      => 'Dana Driver',
        'phone'     => '+15551234567',
        'email'     => 'dana@example.test',
    ]);
    $driver->setRelation('devices', collect([
        (object) ['platform' => 'android', 'token' => 'fcm-a'],
        (object) ['platform' => 'ios', 'token' => 'apn-a'],
        (object) ['platform' => 'android', 'token' => 'fcm-b'],
        (object) ['platform' => 'web', 'token' => 'web-a'],
    ]));

    $vehicle = new Vehicle();
    $vehicle->setRawAttributes([
        'uuid'       => 'vehicle-uuid',
        'avatar_url' => 'https://cdn.test/vehicle.png',
    ], true);

    expect($driver->photo)->toBe('avatar-object')
        ->and($driver->photo_url)->toBe('https://cdn.test/driver.png')
        ->and($driver->name)->toBe('Dana Driver')
        ->and($driver->phone)->toBe('+15551234567')
        ->and($driver->email)->toBe('dana@example.test')
        ->and($driver->rotation)->toBe(180.0)
        ->and($driver->routeNotificationForFcm())->toBe([0 => 'fcm-a', 2 => 'fcm-b'])
        ->and($driver->routeNotificationForApn())->toBe([1 => 'apn-a'])
        ->and((string) $driver->receivesBroadcastNotificationsOn(new stdClass()))->toBe('driver.driver_public')
        ->and($driver->isVehicleNotAssigned())->toBeTrue()
        ->and($driver->isVehicleAssigned())->toBeFalse()
        ->and($driver->setVehicle($vehicle))->toBe($driver)
        ->and($driver->vehicle_uuid)->toBe('vehicle-uuid')
        ->and($driver->vehicle)->toBe($vehicle)
        ->and($driver->isVehicleAssigned())->toBeTrue()
        ->and($driver->unassignCurrentJob())->toBeTrue()
        ->and($driver->updates)->toBe([['current_job_uuid' => null]])
        ->and($driver->unassignCurrentOrder())->toBeTrue()
        ->and($driver->updates[1])->toBe(['current_job_uuid' => null]);
});

test('driver license expiry status avatar and current order helpers handle edge cases', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 10:00:00'));

    $fresh = new FleetOpsDriverAccessorFake();
    $fresh->setLicenseExpiryAttribute(null);

    $existing         = new FleetOpsDriverAccessorFake();
    $existing->exists = true;
    $existing->setRawAttributes(['license_expiry' => '2026-12-31'], true);
    $existing->setLicenseExpiryAttribute('');

    $parsed = new FleetOpsDriverAccessorFake();
    $parsed->setLicenseExpiryAttribute('July 30 2026');
    $parsed->status = 'active';
    $parsed->status = null;
    $parsed->status = 'off-duty';

    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['avatar_url' => 'https://cdn.test/vehicle-avatar.png'], true);

    $avatarDriver = new FleetOpsDriverAccessorFake();
    $avatarDriver->setRelation('vehicle', $vehicle);

    $order = new FleetOpsDriverOrderAccessorFake();
    $order->setRawAttributes(['uuid' => 'order-uuid'], true);

    $orderDriver = new FleetOpsDriverAccessorFake();
    $orderDriver->setRelation('currentOrder', $order);

    $emptyDriver = new FleetOpsDriverAccessorFake();
    $emptyDriver->setRelation('currentOrder', null);

    expect($fresh->getAttributes()['license_expiry'])->toBeNull()
        ->and($existing->getAttributes()['license_expiry'])->toBe('2026-12-31')
        ->and($parsed->getAttributes()['license_expiry'])->toBe('2026-07-30')
        ->and($parsed->status)->toBe('off-duty')
        ->and($avatarDriver->getAvatarUrlAttribute(null))->toBe('https://cdn.test/vehicle-avatar.png')
        ->and($avatarDriver->loadedMissing)->toBe(['vehicle'])
        ->and($orderDriver->getCurrentOrder())->toBe($order)
        ->and($orderDriver->loadedMissing)->toBe(['currentOrder'])
        ->and($order->loadedMissing)->toBe(['payload'])
        ->and($emptyDriver->getCurrentOrder())->toBeNull()
        ->and($emptyDriver->loadedMissing)->toBe(['currentOrder'])
        ->and($parsed->getActivitylogOptions())->toBeInstanceOf(Spatie\Activitylog\LogOptions::class)
        ->and($parsed->getSlugOptions()->slugField)->toBe('slug');

    Carbon::setTestNow();
});

test('vehicle accessors import mapping and mutable json helpers are stable', function () {
    session(['company' => 'company-uuid']);

    $vehicle = new Vehicle([
        'year'         => 2026,
        'make'         => 'Ford',
        'model'        => 'Transit',
        'trim'         => 'XL',
        'model_type'   => 'cargo',
        'details'      => ['engine' => ['hours' => 100]],
        'specs'        => ['battery' => 'standard'],
        'vin_data'     => ['plant' => 'A'],
        'status'       => 'active',
        'avatar_url'   => 'https://cdn.test/vehicle.png',
    ]);
    $vehicle->setRelation('driver', (object) [
        'name'      => 'Driver One',
        'public_id' => 'driver_public',
        'uuid'      => 'driver-uuid',
    ]);
    $vehicle->setRelation('vendor', (object) [
        'name'      => 'Vendor One',
        'public_id' => 'vendor_public',
    ]);
    $vehicle->setRelation('photo', (object) [
        'url' => 'https://cdn.test/photo.png',
    ]);

    expect($vehicle->status)->toBe('available')
        ->and($vehicle->photo_url)->toBe('https://cdn.test/photo.png')
        ->and($vehicle->display_name)->toBe('2026 Ford Transit XL')
        ->and($vehicle->driver_name)->toBe('Driver One')
        ->and($vehicle->driver_id)->toBe('driver_public')
        ->and($vehicle->driver_uuid)->toBe('driver-uuid')
        ->and($vehicle->vendor_id)->toBe('vendor_public')
        ->and($vehicle->vendor_name)->toBe('Vendor One')
        ->and($vehicle->model_data)->toBe(['type' => 'cargo'])
        ->and($vehicle->getAvatarUrlAttribute('https://cdn.test/direct.png'))->toBe('https://cdn.test/direct.png')
        ->and($vehicle->setDetail('engine.hours', 125))->toMatchArray(['engine' => ['hours' => 125]])
        ->and($vehicle->setDetails(['doors' => 4]))->toMatchArray(['engine' => ['hours' => 125], 'doors' => 4])
        ->and($vehicle->setSpec('battery', 'extended'))->toMatchArray(['battery' => 'extended'])
        ->and($vehicle->setSpecs(['range' => '300km']))->toMatchArray(['battery' => 'extended', 'range' => '300km'])
        ->and($vehicle->setVinData('plant', 'B'))->toMatchArray(['plant' => 'B'])
        ->and($vehicle->setVinDatas(['sequence' => '1001']))->toMatchArray(['plant' => 'B', 'sequence' => '1001']);

    $imported = Vehicle::createFromImport([
        'vehicle_make'     => 'Mercedes',
        'vehicle_model'    => 'Sprinter',
        'vehicle_year'     => 2025,
        'vehicle_trim'     => 'Crew',
        'internal_number'  => 'INT-9',
        'vehicle_plate'    => 'ABC-123',
        'vin_number'       => 'VIN-9',
        'serial'           => 'SER-9',
        'callsign'         => 'CALL-9',
        'vehicle_card_id'  => 'CARD-9',
        'vehicle_type'     => 'van',
    ]);

    expect($imported->company_uuid)->toBe('company-uuid')
        ->and($imported->make)->toBe('Mercedes')
        ->and($imported->model)->toBe('Sprinter')
        ->and($imported->year)->toBe(2025)
        ->and($imported->trim)->toBe('Crew')
        ->and($imported->internal_id)->toBe('INT-9')
        ->and($imported->plate_number)->toBe('ABC-123')
        ->and($imported->vin)->toBe('VIN-9')
        ->and($imported->serial_number)->toBe('SER-9')
        ->and($imported->call_sign)->toBe('CALL-9')
        ->and($imported->fuel_card_number)->toBe('CARD-9')
        ->and($imported->type)->toBe('van')
        ->and($imported->status)->toBe('available')
        ->and($imported->online)->toBeFalse();
});

test('work order accessors scopes checklist helpers and imports are stable', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-10 09:00:00'));
    session(['company' => 'company-uuid']);

    $workOrder = new FleetOpsUpdatingWorkOrderFake();
    $workOrder->setRawAttributes([
        'status'    => 'open',
        'priority'  => 'high',
        'opened_at' => '2026-04-09 09:00:00',
        'due_at'    => '2026-04-11 09:00:00',
        'closed_at' => '2026-04-10 15:00:00',
        'checklist' => [
            ['label' => 'Inspect tires', 'completed' => true],
            ['label' => 'Replace filter', 'completed' => false],
        ],
        'meta'      => ['estimated_duration_hours' => 3.5],
    ], true);
    $workOrder->setRelation('target', (object) ['display_name' => 'Truck 20']);
    $workOrder->setRelation('assignee', (object) ['name' => 'Vendor Crew']);

    expect($workOrder->target_name)->toBe('Truck 20')
        ->and($workOrder->assignee_name)->toBe('Vendor Crew')
        ->and($workOrder->is_overdue)->toBeFalse()
        ->and($workOrder->days_until_due)->toBe(1)
        ->and($workOrder->completion_percentage)->toBe(50.0)
        ->and($workOrder->estimated_duration)->toBe(3.5)
        ->and($workOrder->getActualDuration())->toBe(30.0)
        ->and($workOrder->isOnSchedule())->toBeTrue()
        ->and($workOrder->getPriorityLevel())->toBe(4);

    $closed = new FleetOpsUpdatingWorkOrderFake();
    $closed->setRawAttributes(['status' => 'closed', 'checklist' => []], true);
    expect($closed->completion_percentage)->toBe(100.0)
        ->and($closed->is_overdue)->toBeFalse()
        ->and($closed->days_until_due)->toBeNull();

    $scopeQuery = new FleetOpsManifestScopeQueryFake();
    expect($workOrder->scopeByStatus($scopeQuery, 'open'))->toBe($scopeQuery)
        ->and($workOrder->scopeOpen($scopeQuery))->toBe($scopeQuery)
        ->and($workOrder->scopeOverdue($scopeQuery))->toBe($scopeQuery)
        ->and($workOrder->scopeByPriority($scopeQuery, 'high'))->toBe($scopeQuery)
        ->and($workOrder->scopeAssignedTo($scopeQuery, Vendor::class, 'vendor-uuid'))->toBe($scopeQuery)
        ->and($scopeQuery->calls[0])->toBe(['where', 'status', 'open'])
        ->and($scopeQuery->calls[1])->toBe(['whereIn', 'status', ['open', 'in_progress']])
        ->and($scopeQuery->calls[2][0])->toBe('where')
        ->and($scopeQuery->calls[2][1])->toBe('due_at')
        ->and($scopeQuery->calls[2][2])->toBe('<')
        ->and($scopeQuery->calls[3])->toBe(['whereNotIn', 'status', ['closed', 'canceled']])
        ->and($scopeQuery->calls[4])->toBe(['where', 'priority', 'high'])
        ->and($scopeQuery->calls[5])->toBe(['where', 'assignee_type', Vendor::class])
        ->and($scopeQuery->calls[6])->toBe(['where', 'assignee_uuid', 'vendor-uuid']);

    expect($workOrder->updateChecklistItem(1, ['completed' => true]))->toBeTrue()
        ->and($workOrder->updates[0]['checklist'][1]['completed'])->toBeTrue()
        ->and($workOrder->updateChecklistItem(99, ['completed' => true]))->toBeFalse()
        ->and($workOrder->completeChecklistItem(0, 'user-uuid'))->toBeTrue()
        ->and($workOrder->updates[1]['checklist'][0]['completed_by'])->toBe('user-uuid')
        ->and($workOrder->addChecklistItem(['label' => 'Road test']))->toBeTrue()
        ->and($workOrder->updates[2]['checklist'][2]['completed'])->toBeFalse();

    $imported = FleetOpsUpdatingWorkOrderFake::createFromImport([
        'title'          => 'Oil service',
        'work_type'      => 'maintenance',
        'status'         => 'open',
        'priority'       => 'critical',
        'description'    => 'Change oil',
        'open_date'      => '2026-04-01',
        'due_date'       => '2026-04-15',
        'estimated_cost' => 12000,
        'budget'         => 15000,
        'actual_cost'    => 0,
        'currency'       => 'sgd',
        'cost_center'    => 'OPS',
        'budget_code'    => 'BUD-1',
    ]);

    expect($imported->company_uuid)->toBe('company-uuid')
        ->and($imported->subject)->toBe('Oil service')
        ->and($imported->category)->toBe('maintenance')
        ->and($imported->priority)->toBe('critical')
        ->and($imported->instructions)->toBe('Change oil')
        ->and($imported->opened_at->toDateString())->toBe('2026-04-01')
        ->and($imported->due_at->toDateString())->toBe('2026-04-15')
        ->and($imported->currency)->toBe('SGD')
        ->and($imported->cost_center)->toBe('OPS')
        ->and($imported->budget_code)->toBe('BUD-1');

    Carbon::setTestNow();
});

test('manifest metadata relations scopes and status transitions are stable', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-05 09:30:00'));

    $manifest = new FleetOpsUpdatingManifestFake([
        'company_uuid'     => 'company-uuid',
        'driver_uuid'      => 'driver-uuid',
        'vehicle_uuid'     => 'vehicle-uuid',
        'status'           => 'active',
        'scheduled_date'   => '2026-04-05',
        'total_distance_m' => 12345,
        'total_duration_s' => 3600,
        'stop_count'       => 4,
        'meta'             => ['route' => 'A'],
    ]);
    $manifest->setRelation('driver', (object) ['name' => 'Jane Driver']);
    $manifest->setRelation('vehicle', (object) ['display_name' => 'Truck 12']);

    expect($manifest->getTable())->toBe('manifests')
        ->and($manifest->getFillable())->toContain(
            'company_uuid',
            'driver_uuid',
            'vehicle_uuid',
            'status',
            'scheduled_date',
            'started_at',
            'completed_at',
            'total_distance_m',
            'total_duration_s',
            'stop_count',
            'notes',
            'meta'
        )
        ->and($manifest->getCasts())->toHaveKeys(['meta', 'scheduled_date', 'started_at', 'completed_at'])
        ->and($manifest->getAppends())->toBe(['driver_name', 'vehicle_name', 'completed_stops', 'pending_stops'])
        ->and($manifest->driver_name)->toBe('Jane Driver')
        ->and($manifest->vehicle_name)->toBe('Truck 12');

    $scopeQuery = new FleetOpsManifestScopeQueryFake();

    expect($manifest->scopeForCompany($scopeQuery, 'company-uuid'))->toBe($scopeQuery)
        ->and($manifest->scopeActive($scopeQuery))->toBe($scopeQuery)
        ->and($manifest->scopeForDriver($scopeQuery, 'driver-uuid'))->toBe($scopeQuery)
        ->and($manifest->scopeForVehicle($scopeQuery, 'vehicle-uuid'))->toBe($scopeQuery)
        ->and($scopeQuery->calls)->toBe([
            ['where', 'company_uuid', 'company-uuid'],
            ['whereIn', 'status', ['active', 'in_progress']],
            ['where', 'driver_uuid', 'driver-uuid'],
            ['where', 'vehicle_uuid', 'vehicle-uuid'],
        ]);

    expect($manifest->start())->toBe($manifest)
        ->and($manifest->status)->toBe('in_progress')
        ->and($manifest->updates[0]['status'])->toBe('in_progress')
        ->and($manifest->updates[0]['started_at']->toDateTimeString())->toBe('2026-04-05 09:30:00')
        ->and($manifest->complete())->toBe($manifest)
        ->and($manifest->status)->toBe('completed')
        ->and($manifest->updates[1]['completed_at']->toDateTimeString())->toBe('2026-04-05 09:30:00')
        ->and($manifest->cancel())->toBe($manifest)
        ->and($manifest->status)->toBe('cancelled')
        ->and($manifest->updates[2])->toBe(['status' => 'cancelled']);

    Carbon::setTestNow();
});

test('manifest stop accessors and status transitions are stable', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-05 10:45:00'));

    $manifest = new FleetOpsManifestStopManifestFake();
    $stop     = new FleetOpsUpdatingManifestStopFake([
        'status'               => 'pending',
        'sequence'             => 2,
        'distance_from_prev_m' => 1200,
        'duration_from_prev_s' => 300,
    ]);
    $stop->setRelation('manifest', $manifest);
    $stop->setRelation('order', (object) [
        'trackingNumber' => (object) ['tracking_number' => 'TRK-456'],
        'payload'        => (object) [
            'dropoff' => (object) ['address' => 'Fallback dropoff'],
        ],
    ]);
    $stop->setRelation('place', (object) ['address' => '123 Stop Street']);

    expect($stop->getTable())->toBe('manifest_stops')
        ->and($stop->getFillable())->toContain('manifest_uuid', 'order_uuid', 'place_uuid', 'waypoint_uuid', 'status', 'sequence', 'meta')
        ->and($stop->getCasts())->toHaveKeys(['meta', 'estimated_arrival', 'actual_arrival', 'sequence', 'distance_from_prev_m', 'duration_from_prev_s'])
        ->and($stop->getAppends())->toBe(['tracking_number', 'address'])
        ->and($stop->tracking_number)->toBe('TRK-456')
        ->and($stop->address)->toBe('123 Stop Street')
        ->and($stop->markArrived())->toBe($stop)
        ->and($stop->status)->toBe('arrived')
        ->and($stop->updates[0]['actual_arrival']->toDateTimeString())->toBe('2026-04-05 10:45:00')
        ->and($stop->markCompleted())->toBe($stop)
        ->and($stop->status)->toBe('completed')
        ->and($manifest->autoCompleteChecks)->toBe(1)
        ->and($stop->markSkipped())->toBe($stop)
        ->and($stop->status)->toBe('skipped')
        ->and($manifest->autoCompleteChecks)->toBe(2);

    $stop->setRelation('place', null);

    expect($stop->address)->toBe('Fallback dropoff');

    Carbon::setTestNow();
});

test('payload accessors return loaded payloads and associated orders without querying', function () {
    $order = new Order([
        'uuid'      => 'order-uuid',
        'public_id' => 'order_public',
    ]);
    $payload = new Payload([
        'uuid' => 'payload-uuid',
    ]);
    $payload->setRelation('order', $order);

    $host = new FleetOpsPayloadAccessorHostFake([
        'payload_uuid' => 'payload-uuid',
    ]);
    $host->setRelation('payload', $payload);

    expect($host->getPayload())->toBe($payload)
        ->and($host->getOrder())->toBe($order);
});

test('order accessors mutators and payload association helpers are stable', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 08:00:00'));

    $payload = new FleetOpsLoadedPayloadFake([
        'uuid'      => 'payload_uuid',
    ]);
    $payload->setAttribute('public_id', 'payload_public_id');
    $payload->setRelation('pickup', new FleetOpsPlainPlaceFake(['name' => 'Pickup dock']));
    $payload->setRelation('dropoff', new FleetOpsPlainPlaceFake(['street1' => 'Dropoff street']));
    $payload->setRelation('return', new FleetOpsPlainPlaceFake(['street1' => 'Return counter']));

    $order = new FleetOpsSavingOrderFake([
        'driver_assigned_uuid'  => 'driver_uuid',
        'customer_type'         => Contact::class,
        'facilitator_type'      => Vendor::class,
        'scheduled_at'          => '2026-02-04 09:30:00',
        'dispatched_at'         => null,
        'adhoc'                 => false,
        'orchestrator_priority' => '7',
        'type'                  => 'Express Delivery',
        'status'                => 'Driver Assigned',
    ]);

    $order->setRelation('driverAssigned', (object) ['name' => 'Driver One']);
    $order->setRelation('vehicleAssigned', (object) ['display_name' => 'Van 4']);
    $order->setRelation('trackingNumber', (object) [
        'tracking_number' => 'TN123',
        'qr_code'         => 'qr-data',
    ]);
    $order->setRelation('transaction', (object) ['amount' => 1200, 'currency' => 'USD']);
    $order->setRelation('customer', (object) ['name' => 'Customer One', 'phone' => '+1555000']);
    $order->setRelation('facilitator', (object) ['name' => 'Vendor One']);
    $order->setRelation('payload', $payload);
    $order->setRelation('purchaseRate', (object) ['public_id' => 'purchase_rate_id']);
    $order->setRelation('createdBy', (object) ['name' => 'Creator']);
    $order->setRelation('updatedBy', (object) ['name' => 'Updater']);

    $order->time_window_start = '09:00:00';
    $order->time_window_end   = '2026-02-05 17:00:00';

    expect($order->driver_name)->toBe('Driver One')
        ->and($order->vehicle_name)->toBe('Van 4')
        ->and($order->tracking)->toBe('TN123')
        ->and($order->transaction_amount)->toBe(1200)
        ->and($order->transaction_currency)->toBe('USD')
        ->and($order->customer_name)->toBe('Customer One')
        ->and($order->customer_phone)->toBe('+1555000')
        ->and($order->facilitator_name)->toBe('Vendor One')
        ->and($order->customer_is_contact)->toBeTrue()
        ->and($order->customer_is_vendor)->toBeFalse()
        ->and($order->facilitator_is_vendor)->toBeTrue()
        ->and($order->facilitator_is_contact)->toBeFalse()
        ->and($order->pickup_name)->toBe('Pickup dock')
        ->and($order->dropoff_name)->toBe('Dropoff street')
        ->and($order->return_name)->toBe('Return counter')
        ->and($order->payload_id)->toBe('payload_public_id')
        ->and($order->purchase_rate_id)->toBe('purchase_rate_id')
        ->and($order->qr_code)->toBe('qr-data')
        ->and($order->created_by_name)->toBe('Creator')
        ->and($order->updated_by_name)->toBe('Updater')
        ->and($order->has_driver_assigned)->toBeTrue()
        ->and($order->is_scheduled)->toBeTrue()
        ->and($order->is_assigned_not_dispatched)->toBeTrue()
        ->and($order->is_not_dispatched)->toBeTrue()
        ->and($order->orchestrator_priority)->toBe(7)
        ->and($order->type)->toBe('express-delivery')
        ->and($order->status)->toBe('driver_assigned')
        ->and($order->time_window_start->toDateTimeString())->toBe('2026-02-03 09:00:00')
        ->and($order->time_window_end->toDateTimeString())->toBe('2026-02-05 17:00:00');

    $order->orchestrator_priority = 'not numeric';
    $order->type                  = null;
    $order->status                = null;

    expect($order->orchestrator_priority)->toBe(50)
        ->and($order->type)->toBe('default')
        ->and($order->status)->toBe('created');

    $newPayload           = new FleetOpsLoadedPayloadFake();
    $newPayload->uuidFake = 'new_payload_uuid';
    expect($order->setPayload($newPayload))->toBe($order)
        ->and($order->payload_uuid)->toBe('new_payload_uuid')
        ->and($order->payload)->toBe($newPayload)
        ->and($order->saved)->toBeTrue();

    Carbon::setTestNow();
});

test('order location driver and customer helper branches prefer loaded relations', function () {
    $pickup           = new FleetOpsPlainPlaceFake();
    $pickup->location = new Point(1.1, 2.2);

    $dropoff           = new FleetOpsPlainPlaceFake();
    $dropoff->location = new Point(3.3, 4.4);

    $currentWaypoint = new FleetOpsPlainPlaceFake();
    $currentWaypoint->setRawAttributes(['uuid' => 'current-waypoint'], true);
    $currentWaypoint->location = new Point(5.5, 6.6);

    $firstWaypoint = new FleetOpsPlainPlaceFake();
    $firstWaypoint->setRawAttributes(['uuid' => 'first-waypoint'], true);
    $firstWaypoint->location = new Point(7.7, 8.8);

    $payload = new FleetOpsLoadedPayloadFake();
    $payload->setRawAttributes(['current_waypoint_uuid' => 'current-waypoint'], true);
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('waypoints', collect([$firstWaypoint, $currentWaypoint]));

    $driver = new Driver();
    $driver->setRawAttributes([
        'uuid'      => 'driver-uuid',
        'public_id' => 'driver-public',
    ], true);
    $driver->location = new Point(9.9, 10.1);

    $order = new Order([
        'driver_assigned_uuid' => 'driver-uuid',
        'adhoc'                => true,
    ]);
    $order->setRelation('payload', $payload);
    $order->setRelation('driverAssigned', $driver);

    $waypointOnlyPayload = new FleetOpsLoadedPayloadFake();
    $waypointOnlyPayload->setRawAttributes(['current_waypoint_uuid' => 'current-waypoint'], true);
    $waypointOnlyPayload->setRelation('dropoff', null);
    $waypointOnlyPayload->setRelation('pickup', null);
    $waypointOnlyPayload->setRelation('waypoints', collect([$firstWaypoint, $currentWaypoint]));

    $waypointOnlyOrder = new Order();
    $waypointOnlyOrder->setRelation('payload', $waypointOnlyPayload);
    $waypointOnlyOrder->setRelation('driverAssigned', null);

    $firstWaypointPayload = new FleetOpsLoadedPayloadFake();
    $firstWaypointPayload->setRelation('dropoff', null);
    $firstWaypointPayload->setRelation('pickup', null);
    $firstWaypointPayload->setRelation('waypoints', collect([$firstWaypoint]));

    $firstWaypointOrder = new Order();
    $firstWaypointOrder->setRelation('payload', $firstWaypointPayload);
    $firstWaypointOrder->setRelation('driverAssigned', null);

    $emptyPayload = new FleetOpsLoadedPayloadFake();
    $emptyPayload->setRelation('dropoff', null);
    $emptyPayload->setRelation('pickup', null);
    $emptyPayload->setRelation('waypoints', collect());

    $emptyOrder = new Order();
    $emptyOrder->setRelation('payload', $emptyPayload);
    $emptyOrder->setRelation('driverAssigned', null);

    $customer = new Contact();
    $customer->setRawAttributes(['uuid' => 'contact-uuid'], true);

    $customerOrder = new Order();
    $customerOrder->setCustomer($customer);
    $customerOrder->customer_type    = 'vendor';
    $customerOrder->facilitator_type = 'contact';

    expect($order->getCurrentDestinationLocation())->toBe($dropoff->location)
        ->and($order->getLastLocation())->toBe($driver->location)
        ->and($order->isDriver($driver))->toBeTrue()
        ->and($order->isDriver('driver-uuid'))->toBeTrue()
        ->and($order->isDriver('driver-public'))->toBeTrue()
        ->and($order->isDriver($order->driverAssigned))->toBeTrue()
        ->and($order->isDriver('other-driver'))->toBeFalse()
        ->and($order->is_ready_for_dispatch)->toBeTrue()
        ->and($waypointOnlyOrder->getCurrentDestinationLocation())->toBe($currentWaypoint->location)
        ->and($waypointOnlyOrder->getLastLocation())->toBe($currentWaypoint->location)
        ->and($firstWaypointOrder->getCurrentDestinationLocation())->toBe($firstWaypoint->location)
        ->and($firstWaypointOrder->getLastLocation())->toBe($firstWaypoint->location)
        ->and($emptyOrder->getCurrentDestinationLocation())->toBeInstanceOf(Point::class)
        ->and($emptyOrder->getCurrentDestinationLocation()->getLat())->toBe(0.0)
        ->and($emptyOrder->getLastLocation())->toBeInstanceOf(Point::class)
        ->and($emptyOrder->getLastLocation()->getLng())->toBe(0.0)
        ->and($customerOrder->customer_uuid)->toBe('contact-uuid')
        ->and($customerOrder->customer_type)->toBe(Vendor::class)
        ->and($customerOrder->facilitator_type)->toBe(Contact::class);
});

test('payload and place pure accessors normalize fallback data', function () {
    $pickup = new FleetOpsPlainPlaceFake();
    $pickup->setRawAttributes(['name' => 'Pickup name', 'country' => 'SG', 'uuid' => '11111111-1111-4111-8111-111111111111'], true);

    $dropoff = new FleetOpsPlainPlaceFake();
    $dropoff->setRawAttributes(['street1' => 'Dropoff street', 'country' => 'MY', 'uuid' => '22222222-2222-4222-8222-222222222222'], true);

    $return = new FleetOpsPlainPlaceFake(['street1' => 'Return address']);

    $waypoint = new Place();
    $waypoint->setRawAttributes(['name' => 'Waypoint one', 'uuid' => '33333333-3333-4333-8333-333333333333'], true);

    $payload = new FleetOpsLoadedPayloadFake(['cod_amount' => '$12.34']);
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('return', $return);
    $payload->setRelation('waypoints', collect([
        $waypoint,
        ['name' => 'Waypoint array'],
        (object) ['name' => 'Ignored object'],
    ]));

    expect($payload->cod_amount)->toBe(1234)
        ->and($payload->pickup_name)->toBe('Pickup name')
        ->and($payload->dropoff_name)->toBe('Dropoff street')
        ->and($payload->return_name)->toBe('Return address')
        ->and($payload->getPickupRegion())->toBe('SG')
        ->and($payload->getCountryCode())->toBe('SG')
        ->and($payload->index_pickup_place)->toBe($pickup)
        ->and($payload->index_dropoff_place)->toBe($dropoff)
        ->and($payload->is_multiple_drop_order)->toBeFalse()
        ->and($payload->getAllStops())->toHaveCount(4)
        ->and($payload->getPickupLocation())->toBeInstanceOf(Point::class)
        ->and($payload->findDestinationFromKey())->toBeNull()
        ->and($payload->findDestinationFromKey('0'))->toBe($waypoint)
        ->and($payload->findDestinationFromKey('pickup'))->toBe($pickup)
        ->and($payload->findDestinationFromKey('dropoff'))->toBe($dropoff)
        ->and($payload->findDestinationFromKey('33333333-3333-4333-8333-333333333333'))->toBe($waypoint)
        ->and($payload->findDestinationFromKey('22222222-2222-4222-8222-222222222222'))->toBe($dropoff);

    $place = new Place([
        'street1'     => '  1 Main Street  ',
        'street2'     => '',
        'city'        => '  Singapore ',
        'country'     => ' SG ',
        'postal_code' => null,
    ]);

    expect(Place::composeGeocodingQuery($place->getAttributes()))->toBe('1 Main Street, Singapore, SG')
        ->and(Place::normalizePlaceValue(['bad']))->toBeNull()
        ->and(Place::normalizePlaceValue('  ok  '))->toBe('ok')
        ->and(Place::mergeStructuredPlaceAttributes([
            'street1' => '  Explicit  ',
            'street2' => '',
            'city'    => '  City  ',
            'phone'   => '  +1555 ',
        ], [
            'street1' => 'Geocoded',
            'country' => 'US',
        ]))->toMatchArray([
            'street1' => 'Explicit',
            'city'    => 'City',
            'country' => 'US',
            'phone'   => '+1555',
        ]);
});

test('shared place hydration fills only safe missing fields and zero locations', function () {
    $existing = new FleetOpsHydratablePlaceFake();
    $existing->setRawAttributes([
        'name'        => null,
        'street1'     => '1 Existing Street',
        'street2'     => null,
        'province'    => '',
        'postal_code' => null,
        'phone'       => ' +1555000 ',
        'location'    => new Point(0, 0),
    ], true);

    $location = new Point(1.3521, 103.8198);

    $hydrated = Place::hydrateSharedPlace($existing, [
        'name'        => '  Warehouse A ',
        'street2'     => ' Unit 4 ',
        'province'    => ' Central ',
        'postal_code' => ' 018956 ',
        'phone'       => '+1555999',
        'location'    => $location,
    ]);

    expect($hydrated)->toBe($existing)
        ->and($existing->name)->toBe('Warehouse A')
        ->and($existing->street2)->toBe('Unit 4')
        ->and($existing->province)->toBe('Central')
        ->and($existing->postal_code)->toBe('018956')
        ->and($existing->phone)->toBe(' +1555000 ')
        ->and($existing->location)->toBe($location)
        ->and($existing->saved)->toBeTrue();

    $complete = new FleetOpsHydratablePlaceFake();
    $complete->setRawAttributes([
        'name'     => 'Complete Place',
        'street2'  => 'Level 2',
        'location' => new Point(1, 2),
    ], true);

    Place::hydrateSharedPlace($complete, [
        'name'     => 'Ignored',
        'street2'  => 'Ignored',
        'location' => new Point(3, 4),
    ]);

    expect($complete->name)->toBe('Complete Place')
        ->and($complete->street2)->toBe('Level 2')
        ->and($complete->saved)->toBeFalse();
});

test('place import rows use aliases coordinates metadata and save options without geocoding', function () {
    FleetOpsImportablePlaceFake::$lastGeocodedAddress = null;

    $imported = FleetOpsImportablePlaceFake::createFromImportRow([
        'house_number'   => '42',
        'street'         => 'Depot Road',
        'unit_number'    => 'Dock 5',
        'town'           => 'Singapore',
        'district'       => 'Central',
        'state'          => 'SG',
        'zip'            => '018956',
        'lat'            => '1.3001',
        'lng'            => '103.8002',
        'mobile_number'  => '+6512345678',
        'custom_context' => 'fragile',
    ], 'import-uuid', 'SG');

    expect(FleetOpsImportablePlaceFake::$lastGeocodedAddress)->toBe('1.3001, 103.8002')
        ->and($imported)->toBeInstanceOf(FleetOpsImportablePlaceFake::class)
        ->and($imported->street1)->toBe('Depot Road')
        ->and($imported->street2)->toBe('Dock 5')
        ->and($imported->city)->toBe('Singapore')
        ->and($imported->neighborhood)->toBe('Central')
        ->and($imported->province)->toBe('SG')
        ->and($imported->postal_code)->toBe('018956')
        ->and($imported->phone)->toBe('+6512345678')
        ->and($imported->location)->not->toBeNull()
        ->and($imported->getAttribute('_import_id'))->toBe('import-uuid')
        ->and(data_get($imported->meta, 'custom_context'))->toBe('fragile');

    FleetOpsImportablePlaceFake::$lastGeocodedAddress = null;

    $saved = FleetOpsImportablePlaceFake::createFromImport([
        'street' => '55 Warehouse Way',
        'city'   => 'Singapore',
        'phone'  => '+6500000000',
    ], true);

    expect(FleetOpsImportablePlaceFake::$lastGeocodedAddress)->toBe('55 Warehouse Way Singapore')
        ->and($saved)->toBeInstanceOf(FleetOpsImportablePlaceFake::class)
        ->and($saved->company_uuid)->toBe(session('company'))
        ->and($saved->saved)->toBeTrue();
});

test('telematic model accessors relationships scopes and heartbeat contracts are stable', function () {
    fleetopsModelAccessorsUseInMemoryRelationConnection();
    Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00'));

    app()->instance(TelematicProviderRegistry::class, new FleetOpsModelAccessorTelematicRegistryFake(new class {
        public function toArray(): array
        {
            return [
                'key'                => 'safee',
                'label'              => 'Safee',
                'supports_webhooks'  => true,
                'supports_discovery' => true,
                'metadata'           => ['region' => 'sg'],
            ];
        }
    }));

    $telematic = new FleetOpsTelematicUpdatingFake();
    $telematic->setRawAttributes([
        'uuid'             => 'telematic-uuid',
        'provider'         => 'safee',
        'firmware_version' => '1.2.0',
        'last_seen_at'     => Carbon::parse('2026-07-26 11:58:00'),
        'last_metrics'     => ['signal_strength' => 87, 'lat' => 1.31, 'lng' => 103.82],
        'config'           => [
            'supported_features' => ['ignition', 'fuel'],
            'latest_firmware'    => '1.3.0',
        ],
    ], true);
    $telematic->setRelation('warranty', (object) ['name' => 'Gold Coverage']);

    expect($telematic->warranty())->toBeInstanceOf(BelongsTo::class)
        ->and($telematic->createdBy()->getForeignKeyName())->toBe('created_by_uuid')
        ->and($telematic->updatedBy()->getForeignKeyName())->toBe('updated_by_uuid')
        ->and($telematic->device()->getForeignKeyName())->toBe('telematic_uuid')
        ->and($telematic->assets()->getForeignKeyName())->toBe('telematic_uuid')
        ->and($telematic->provider_descriptor)->toMatchArray([
            'key'                => 'safee',
            'label'              => 'Safee',
            'supports_webhooks'  => true,
            'supports_discovery' => true,
            'metadata'           => ['region' => 'sg'],
        ])
        ->and($telematic->warranty_name)->toBe('Gold Coverage')
        ->and($telematic->is_online)->toBeTrue()
        ->and($telematic->signal_strength)->toBe(87)
        ->and($telematic->last_location)->toBe([
            'latitude'  => 1.31,
            'longitude' => 103.82,
            'timestamp' => '2026-07-26T11:58:00.000000Z',
        ])
        ->and($telematic->getConnectionStatus())->toBe('online')
        ->and($telematic->supportsFeature('fuel'))->toBeTrue()
        ->and($telematic->supportsFeature('doors'))->toBeFalse()
        ->and($telematic->getFirmwareStatus())->toBe([
            'current_version'  => '1.2.0',
            'latest_version'   => '1.3.0',
            'update_available' => true,
        ])
        ->and($telematic->getActivitylogOptions())->toBeInstanceOf(Spatie\Activitylog\LogOptions::class);

    $offline = new Telematic();
    $offline->setRawAttributes(['last_seen_at' => Carbon::parse('2026-07-26 11:30:00')], true);
    $old = new Telematic();
    $old->setRawAttributes(['last_seen_at' => Carbon::parse('2026-07-26 05:00:00')], true);
    $longOffline = new Telematic();
    $longOffline->setRawAttributes(['last_seen_at' => Carbon::parse('2026-07-24 05:00:00')], true);

    expect((new Telematic())->is_online)->toBeFalse()
        ->and((new Telematic())->last_location)->toBeNull()
        ->and((new Telematic())->getProviderDescriptorAttribute())->toBe([])
        ->and((new Telematic())->getConnectionStatus())->toBe('never_connected')
        ->and($offline->getConnectionStatus())->toBe('recently_offline')
        ->and($old->getConnectionStatus())->toBe('offline')
        ->and($longOffline->getConnectionStatus())->toBe('long_offline');

    $telematic->updateHeartbeat(['battery' => 92]);

    expect($telematic->updated['last_seen_at']->toDateTimeString())->toBe('2026-07-26 12:00:00')
        ->and($telematic->updated['last_metrics'])->toMatchArray([
            'signal_strength' => 87,
            'lat'             => 1.31,
            'lng'             => 103.82,
            'battery'         => 92,
        ]);

    $onlineQuery = new FleetOpsTelematicQueryFake();
    (new Telematic())->scopeOnline($onlineQuery);

    $providerQuery = new FleetOpsTelematicQueryFake();
    (new Telematic())->scopeByProvider($providerQuery, 'safee');

    $offlineQuery = new FleetOpsTelematicQueryFake();
    (new Telematic())->scopeOffline($offlineQuery);
    $offlineCallback = $offlineQuery->calls[0][1][0];
    $nestedQuery     = new FleetOpsTelematicQueryFake();
    $offlineCallback($nestedQuery);

    expect($onlineQuery->calls[0][0])->toBe('where')
        ->and($onlineQuery->calls[0][1][0])->toBe('last_seen_at')
        ->and($onlineQuery->calls[0][1][1])->toBe('>=')
        ->and($onlineQuery->calls[0][1][2]->toDateTimeString())->toBe('2026-07-26 11:55:00')
        ->and($providerQuery->calls)->toBe([
            ['where', ['provider', 'safee']],
        ])
        ->and($nestedQuery->calls[0])->toBe(['whereNull', ['last_seen_at']])
        ->and($nestedQuery->calls[1][0])->toBe('orWhere')
        ->and($nestedQuery->calls[1][1][0])->toBe('last_seen_at')
        ->and($nestedQuery->calls[1][1][1])->toBe('<')
        ->and($nestedQuery->calls[1][1][2]->toDateTimeString())->toBe('2026-07-26 11:55:00');

    Carbon::setTestNow();
});

test('service rate quote math and fee selection helpers are stable', function () {
    $rate = new FleetOpsLoadedServiceRateFake([
        'uuid'                          => 'rate_uuid',
        'base_fee'                      => 500,
        'currency'                      => 'USD',
        'rate_calculation_method'       => 'per_meter',
        'per_meter_flat_rate_fee'       => 2,
        'per_meter_unit'                => 'km',
        'has_cod_fee'                   => true,
        'cod_calculation_method'        => 'flat',
        'cod_flat_fee'                  => 125,
        'has_peak_hours_fee'            => true,
        'peak_hours_calculation_method' => 'percentage',
        'peak_hours_percent'            => 10,
        'peak_hours_start'              => '00:00',
        'peak_hours_end'                => '23:59',
    ]);

    $shortFee = new ServiceRateFee(['distance' => 5, 'min' => 1, 'max' => 2, 'fee' => 100]);
    $shortFee->setAttribute('uuid', 'short');
    $longFee = new ServiceRateFee(['distance' => 10, 'min' => 3, 'max' => 6, 'fee' => 200]);
    $longFee->setAttribute('uuid', 'long');
    $rate->setRelation('rateFees', collect([$shortFee, $longFee]));

    [$total, $lines] = $rate->quoteFromPreliminaryData(
        [(object) ['type' => 'parcel', 'weight' => 2, 'weight_unit' => 'lb']],
        [new Place(['name' => 'A']), new Place(['name' => 'B']), new Place(['name' => 'C'])],
        2500,
        600,
        true
    );

    $reflection         = new ReflectionClass(ServiceRate::class);
    $distanceNormalizer = $reflection->getMethod('normalizeDistanceForUnit');
    $moneyNormalizer    = $reflection->getMethod('normalizeCalculatedMoney');
    $variableBuilder    = $reflection->getMethod('buildAlgorithmVariables');
    $endpointInferrer   = $reflection->getMethod('inferEndpointCountFromStops');
    $weightNormalizer   = $reflection->getMethod('normalizeEntityWeightToKilograms');
    $haversine          = $reflection->getMethod('haversineDistanceInMeters');
    $interpolator       = $reflection->getMethod('interpolateLngLat');

    expect($total)->toBe(681)
        ->and($lines)->toHaveCount(4)
        ->and($rate->findServiceRateFeeByDistance(7500)->uuid)->toBe('long')
        ->and($rate->findServiceRateFeeByDistance(12000)->uuid)->toBe('long')
        ->and($rate->findServiceRateFeeByMinMax(4)->uuid)->toBe('long')
        ->and($rate->findServiceRateFeeByMinMax(99)->uuid)->toBe('long')
        ->and($distanceNormalizer->invoke($rate, 1000, 'km'))->toBe(1.0)
        ->and(round($distanceNormalizer->invoke($rate, 1609.344, 'mi'), 3))->toBe(1.0)
        ->and($moneyNormalizer->invoke($rate, 10.6))->toBe(11)
        ->and($endpointInferrer->invoke($rate, [1, 2, 3]))->toBe(2)
        ->and($endpointInferrer->invoke($rate, [1]))->toBe(1)
        ->and(round($weightNormalizer->invoke($rate, ['weight' => 1000, 'weight_unit' => 'g']), 2))->toBe(1.0)
        ->and(round($weightNormalizer->invoke($rate, ['weight' => 16, 'weight_unit' => 'oz']), 4))->toBe(0.4536)
        ->and($interpolator->invoke($rate, ['lat' => 0, 'lng' => 0], ['lat' => 10, 'lng' => 20], 0.25))->toBe(['lat' => 2.5, 'lng' => 5.0])
        ->and((int) round($haversine->invoke($rate, 0, 0, 0, 1)))->toBe(111195);

    $variables = $variableBuilder->invoke($rate, [
        ['type' => 'parcel', 'weight' => 1000, 'weight_unit' => 'g'],
        ['type' => 'item', 'weight' => 2, 'weight_unit' => 'kg'],
    ], [new Place(), new Place(), new Place()], 1200, 300, 2);

    expect($variables)->toMatchArray([
        'distance_m' => 1200,
        'time_s'     => 300,
        'stops'      => 3,
        'waypoints'  => 1,
        'parcels'    => 1,
        'entities'   => 2,
        'base_fee'   => 500,
        'weight_kg'  => 3.0,
    ]);
});

test('purchase rate accessors expose relation identifiers and customer type flags', function () {
    $rate = new PurchaseRate();
    $rate->setRawAttributes([
        'customer_type' => Vendor::class,
    ], true);

    expect($rate->getCustomerIsVendorAttribute())->toBeTrue()
        ->and($rate->getCustomerIsContactAttribute())->toBeFalse()
        ->and($rate->getAmountAttribute())->toBe(0)
        ->and($rate->getCurrencyAttribute())->toBeNull()
        ->and($rate->getServiceQuoteIdAttribute())->toBeNull()
        ->and($rate->getOrderIdAttribute())->toBeNull()
        ->and($rate->getCustomerIdAttribute())->toBeNull()
        ->and($rate->getTransactionIdAttribute())->toBeNull();

    $rate->setRawAttributes([
        'customer_type' => Contact::class,
    ], true);
    $rate->setRelation('serviceQuote', (object) [
        'amount'    => 1299,
        'currency'  => 'USD',
        'public_id' => 'quote_public',
    ]);
    $rate->setRelation('order', (object) [
        'public_id' => 'order_public',
    ]);
    $rate->setRelation('customer', (object) [
        'public_id' => 'contact_public',
    ]);
    $rate->setRelation('transaction', (object) [
        'public_id' => 'transaction_public',
    ]);

    expect($rate->getCustomerIsVendorAttribute())->toBeFalse()
        ->and($rate->getCustomerIsContactAttribute())->toBeTrue()
        ->and($rate->getAmountAttribute())->toBe(1299)
        ->and($rate->getCurrencyAttribute())->toBe('USD')
        ->and($rate->getServiceQuoteIdAttribute())->toBe('quote_public')
        ->and($rate->getOrderIdAttribute())->toBe('order_public')
        ->and($rate->getCustomerIdAttribute())->toBe('contact_public')
        ->and($rate->getTransactionIdAttribute())->toBe('transaction_public');
});

test('tracking number and status accessors normalize status contracts', function () {
    $createdAt = Carbon::parse('2026-08-01 12:30:00');
    $status    = new TrackingStatus();
    $status->setRawAttributes([
        'status'     => 'Out for Delivery',
        'code'       => 'OUT_FOR_DELIVERY',
        'complete'   => true,
        'created_at' => $createdAt,
    ], true);

    $trackingNumber = new FleetOpsTrackingNumberAccessorFake();
    $trackingNumber->setRawAttributes([
        'owner_type' => Order::class,
    ], true);
    $trackingNumber->setRelation('status', $status);

    expect($trackingNumber->getLastStatusAttribute())->toBe('Out for Delivery')
        ->and($trackingNumber->getLastStatusCodeAttribute())->toBe('OUT_FOR_DELIVERY')
        ->and($trackingNumber->getLastStatusUpdatedAtAttribute()->toDateTimeString())->toBe($createdAt->toDateTimeString())
        ->and($trackingNumber->getLastStatusCompleteAttribute())->toBeTrue()
        ->and($trackingNumber->getTypeAttribute())->toBe('order')
        ->and($trackingNumber->loaded)->toBe([
            'status',
            'status',
            'status',
            'status',
        ]);

    $normalized = new TrackingStatus();
    $normalized->setCodeAttribute(' Out for delivery! ');
    $normalized->setStatusAttribute('out for delivery');

    expect($normalized->getAttribute('code'))->toBe('_OUT_FOR_DELIVERY_')
        ->and($normalized->getAttribute('status'))->toBe('Out For Delivery')
        ->and(TrackingStatus::prepareCode('arrived @ hub'))->toBe('ARRIVED__HUB')
        ->and($status->isComplete())->toBeTrue();
});

test('tracking number updates fillable owner status when status changes', function () {
    $trackingStatus = new TrackingStatus();
    $trackingStatus->setRawAttributes([
        'code' => 'IN_TRANSIT',
    ], true);

    $owner = new FleetOpsTrackingNumberOwnerFake();
    $owner->setRawAttributes([
        'uuid'   => 'owner-uuid',
        'status' => 'created',
    ], true);

    $trackingNumber = new FleetOpsTrackingNumberAccessorFake();
    $trackingNumber->setRelation('owner', $owner);

    expect($trackingNumber->updateOwnerStatus($trackingStatus))->toBe($trackingNumber)
        ->and($owner->status)->toBe('in_transit')
        ->and($owner->saved)->toBeTrue()
        ->and($trackingNumber->loaded)->toBe([
            ['owner'],
        ]);
});
