<?php

use Carbon\Carbon;
use Fleetbase\FleetOps\Listeners\NotifyDriverOnShiftChange;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\PurchaseRate;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\FleetOps\Notifications\DriverShiftChanged;
use Fleetbase\FleetOps\Observers\CategoryObserver;
use Fleetbase\FleetOps\Observers\CompanyObserver;
use Fleetbase\FleetOps\Observers\CompanyUserObserver;
use Fleetbase\FleetOps\Observers\ContactObserver;
use Fleetbase\FleetOps\Observers\DriverObserver;
use Fleetbase\FleetOps\Observers\FleetObserver;
use Fleetbase\FleetOps\Observers\OrderObserver;
use Fleetbase\FleetOps\Observers\PayloadObserver;
use Fleetbase\FleetOps\Observers\PlaceObserver;
use Fleetbase\FleetOps\Observers\PurchaseRateObserver;
use Fleetbase\FleetOps\Observers\ServiceAreaObserver;
use Fleetbase\FleetOps\Observers\ServiceRateObserver;
use Fleetbase\FleetOps\Observers\TrackingNumberObserver;
use Fleetbase\FleetOps\Observers\UserObserver;
use Fleetbase\FleetOps\Observers\VehicleObserver;
use Fleetbase\FleetOps\Observers\WorkOrderObserver;
use Fleetbase\FleetOps\Observers\ZoneObserver;
use Fleetbase\Models\Category;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Schedule;
use Fleetbase\Models\ScheduleItem;
use Fleetbase\Models\Transaction;
use Fleetbase\Models\TransactionItem;
use Fleetbase\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;

if (!class_exists('Illuminate\Foundation\Auth\User')) {
    class_alias(Illuminate\Database\Eloquent\Model::class, 'Illuminate\Foundation\Auth\User');
}

class FleetOpsWorkOrderObserverProbe extends WorkOrderObserver
{
    public bool $maintenanceExists        = false;
    public ?MaintenanceSchedule $schedule = null;
    public array $createdMaintenance      = [];
    public array $completedEvents         = [];

    protected function hasMaintenanceRecord(WorkOrder $workOrder): bool
    {
        return $this->maintenanceExists;
    }

    protected function createMaintenance(array $attributes): Maintenance
    {
        $this->createdMaintenance[] = $attributes;

        return new Maintenance();
    }

    protected function findSchedule(string $uuid): ?MaintenanceSchedule
    {
        return $this->schedule && $this->schedule->uuid === $uuid ? $this->schedule : null;
    }

    protected function dispatchCompletedEvent(WorkOrder $workOrder): void
    {
        $this->completedEvents[] = $workOrder;
    }
}

class FleetOpsWorkOrderObserverWorkOrderFake extends WorkOrder
{
    public bool $statusWasChanged = true;

    public function wasChanged($attributes = null): bool
    {
        return $attributes === 'status' ? $this->statusWasChanged : false;
    }
}

class FleetOpsWorkOrderObserverScheduleFake extends MaintenanceSchedule
{
    public array $resets = [];

    public function resetAfterCompletion(?int $completedOdometer = null, ?int $completedEngineHours = null, ?Carbon $completedAt = null): bool
    {
        $this->resets[] = [$completedOdometer, $completedEngineHours, $completedAt];

        return true;
    }
}

class FleetOpsOrderObserverOrderFake extends Order
{
    public ?string $uuid           = null;
    public ?string $status         = null;
    public bool $started           = false;
    public ?Carbon $started_at     = null;
    public bool $statusDirty       = false;
    public bool $driverWasChanged  = false;
    public bool $integratedVendor  = false;
    public array $originalData     = [];
    public array $events           = [];

    public function isDirty($attributes = null): bool
    {
        return $attributes === 'status' ? $this->statusDirty : false;
    }

    public function getOriginal($key = null, $default = null): mixed
    {
        return $key === null ? $this->originalData : ($this->originalData[$key] ?? $default);
    }

    public function wasChanged($attributes = null): bool
    {
        return $attributes === 'driver_assigned_uuid' ? $this->driverWasChanged : false;
    }

    public function setDriverLocationAsPickup($force = false)
    {
        $this->events[] = 'setDriverLocationAsPickup';
    }

    public function notifyDriverAssigned(): void
    {
        $this->events[] = 'notifyDriverAssigned';
    }

    public function isIntegratedVendorOrder(): bool
    {
        return $this->integratedVendor;
    }
}

class FleetOpsContactObserverContactFake extends Contact
{
    public bool $hasUser       = false;
    public bool $customer      = false;
    public bool $typeDirty     = false;
    public bool $emailChanged  = false;
    public bool $phoneChanged  = false;
    public array $events       = [];
    public array $originalData = [];

    public function doesntHaveUser(): bool
    {
        $this->events[] = 'doesntHaveUser';

        return !$this->hasUser;
    }

    public function createUser(bool $sendInvite = false): User
    {
        $this->events[] = 'createUser';
        $this->hasUser  = true;

        return new User();
    }

    public function assertCustomerIdentityIsAvailable(): void
    {
        $this->events[] = 'assertCustomerIdentityIsAvailable';
    }

    public function isCustomer(): bool
    {
        $this->events[] = 'isCustomer';

        return $this->customer;
    }

    public function normalizeCustomerUser(?User $user = null, bool $quiet = false): ?User
    {
        $this->events[] = 'normalizeCustomerUser';

        return $user ?? new User();
    }

    public function syncWithUser(): bool
    {
        $this->events[] = 'syncWithUser';

        return true;
    }

    public function deleteUser(): ?bool
    {
        $this->events[] = 'deleteUser';

        return true;
    }

    public function getOriginal($key = null, $default = null): mixed
    {
        return $key === null ? $this->originalData : ($this->originalData[$key] ?? $default);
    }

    public function isDirty($attributes = null): bool
    {
        return $attributes === 'type' ? $this->typeDirty : false;
    }

    public function wasChanged($attributes = null): bool
    {
        return match ($attributes) {
            'email' => $this->emailChanged,
            'phone' => $this->phoneChanged,
            default => false,
        };
    }
}

class FleetOpsDriverObserverProbe extends DriverObserver
{
    public array $invalidations = [];
    public array $unassigned    = [];
    public ?User $user          = null;

    protected function invalidateLiveCache(): void
    {
        $this->invalidations[] = ['drivers', 'operations-monitor'];
    }

    protected function unassignOrders(Driver $driver): int
    {
        $this->unassigned[] = $driver->uuid;

        return 1;
    }

    protected function findDriverUser(Driver $driver): ?User
    {
        return $this->user;
    }
}

class FleetOpsDriverObserverUserFake extends User
{
    public bool $driverRole = true;
    public bool $deleted    = false;

    public function hasRole($roles, ?string $guard = null): bool
    {
        return $roles === 'Driver' && $this->driverRole;
    }

    public function delete()
    {
        $this->deleted = true;

        return true;
    }
}

class FleetOpsVehicleObserverProbe extends VehicleObserver
{
    public ?string $identifier = null;
    public ?Driver $driver     = null;
    public array $deleted      = [];
    public array $invalidated  = [];

    protected function getDriverIdentifier(): ?string
    {
        return $this->identifier;
    }

    protected function findDriver(string $identifier): ?Driver
    {
        return $this->driver && $identifier === $this->identifier ? $this->driver : null;
    }

    protected function deleteDriversAssignedTo(Vehicle $vehicle): mixed
    {
        $this->deleted[] = $vehicle->uuid;

        return 1;
    }

    protected function invalidateLiveCache(): void
    {
        $this->invalidated[] = ['vehicles', 'operations-monitor'];
    }
}

class FleetOpsVehicleObserverDriverFake extends Driver
{
    public array $assignments = [];

    public function assignVehicle(Vehicle $vehicle): self
    {
        $this->assignments[] = $vehicle;

        return $this;
    }
}

class FleetOpsServiceRateObserverProbe extends ServiceRateObserver
{
    public mixed $rateFeesInput       = null;
    public mixed $parcelFeesInput     = null;
    public array $deletedModelBatches = [];

    protected function rateFeesInput(): mixed
    {
        return $this->rateFeesInput;
    }

    protected function parcelFeesInput(): mixed
    {
        return $this->parcelFeesInput;
    }

    protected function deleteModels(mixed $models): void
    {
        $this->deletedModelBatches[] = $models;
    }
}

class FleetOpsServiceRateObserverServiceRateFake extends ServiceRate
{
    public bool $fixedMeter       = false;
    public bool $perDrop          = false;
    public bool $multiZone        = false;
    public bool $parcelService    = false;
    public array $rateFeeCalls    = [];
    public array $parcelFeeCalls  = [];
    public bool $relationsLoaded  = false;

    public function isFixedMeter(): bool
    {
        return $this->fixedMeter;
    }

    public function isPerDrop(): bool
    {
        return $this->perDrop;
    }

    public function isMultiZoneDistance(): bool
    {
        return $this->multiZone;
    }

    public function isParcelService(): bool
    {
        return $this->parcelService;
    }

    public function setServiceRateFees(?array $serviceRateFees = [])
    {
        $this->rateFeeCalls[] = $serviceRateFees;

        return $this;
    }

    public function setServiceRateParcelFees(?array $serviceRateParcelFees = [])
    {
        $this->parcelFeeCalls[] = $serviceRateParcelFees;

        return $this;
    }

    public function load($relations)
    {
        $this->relationsLoaded = $relations === ['parcelFees', 'rateFees'];
        $this->setRelation('parcelFees', new EloquentCollection(['parcel-fee']));
        $this->setRelation('rateFees', new EloquentCollection(['rate-fee']));

        return $this;
    }
}

class FleetOpsTrackingNumberObserverProbe extends TrackingNumberObserver
{
    public array $barcodes = [];
    public array $statuses = [];

    protected function generateTrackingNumber(TrackingNumber $trackingNumber): string
    {
        return 'TN-' . $trackingNumber->region;
    }

    protected function generateBarcode(string $ownerUuid, string $type): string
    {
        $this->barcodes[] = [$ownerUuid, $type];

        return $type . '-png';
    }

    protected function createTrackingStatus(array $attributes): TrackingStatus
    {
        $this->statuses[] = $attributes;

        $status = new TrackingStatus();
        $status->setRawAttributes(array_merge(['uuid' => 'status-uuid'], $attributes), true);

        return $status;
    }
}

class FleetOpsTrackingNumberObserverTrackingNumberFake extends TrackingNumber
{
    public array $ownerStatuses = [];

    public function updateOwnerStatus(?TrackingStatus $trackingStatus = null)
    {
        $this->ownerStatuses[] = $trackingStatus;

        return $this;
    }
}

class FleetOpsZoneObserverProbe extends ZoneObserver
{
    public array $invalidations = [];

    protected function invalidateServiceAreaCache(Zone $zone, ?string $serviceAreaUuid = null): void
    {
        $serviceAreaUuid ??= $zone->service_area_uuid;
        if (!$serviceAreaUuid) {
            return;
        }

        $this->invalidations[] = [$zone->company_uuid, $serviceAreaUuid];
    }
}

class FleetOpsNotifyDriverOnShiftChangeProbe extends NotifyDriverOnShiftChange
{
    public ?Schedule $schedule = null;
    public array $settings     = [];
    public bool $createdEvent  = false;
    public array $sent         = [];

    protected function getSchedule(ScheduleItem $scheduleItem): ?Schedule
    {
        return $this->schedule;
    }

    protected function getSchedulingSettings(): array
    {
        return $this->settings;
    }

    protected function isCreatedEvent(object $event): bool
    {
        return $this->createdEvent;
    }

    protected function notifyDriver(Driver $driver, DriverShiftChanged $notification): void
    {
        $this->sent[] = [$driver, $notification];
    }
}

class FleetOpsFleetObserverProbe extends FleetObserver
{
    public array $cleared = [];

    protected function clearParentFleet(string $fleetUuid): void
    {
        $this->cleared[] = $fleetUuid;
    }
}

class FleetOpsServiceAreaObserverProbe extends ServiceAreaObserver
{
    public array $countries = [];
    public array $deleted   = [];

    protected function createPolygonFromCountry(string $country): mixed
    {
        $this->countries[] = $country;

        return 'polygon-' . $country;
    }

    protected function deleteModels(mixed $models): void
    {
        $this->deleted[] = $models;
    }
}

class FleetOpsServiceAreaObserverServiceAreaFake extends ServiceArea
{
    public function getAttribute($key)
    {
        if ($key === 'border') {
            return $this->attributes['border'] ?? null;
        }

        return parent::getAttribute($key);
    }

    public function setAttribute($key, $value)
    {
        if ($key === 'border') {
            $this->attributes['border'] = $value;

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    public function load($relations)
    {
        return $this;
    }
}

class FleetOpsCompanyUserObserverProbe extends CompanyUserObserver
{
    public array $deleted  = [];
    public ?Driver $driver = null;

    protected function deleteDrivers(string $userUuid): void
    {
        $this->deleted[] = $userUuid;
    }

    protected function findDriver(string $userUuid): ?Driver
    {
        return $this->driver;
    }
}

class FleetOpsCompanyUserFake extends CompanyUser
{
    public bool $statusChanged = true;

    public function wasChanged($attributes = null): bool
    {
        return $attributes === 'status' ? $this->statusChanged : false;
    }
}

class FleetOpsObserverDriverFake extends Driver
{
    public array $updates = [];

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;

        return true;
    }
}

class FleetOpsCategoryObserverProbe extends CategoryObserver
{
    public array $deleted = [];

    protected function deleteCustomFields(string $categoryUuid): void
    {
        $this->deleted[] = $categoryUuid;
    }
}

class FleetOpsCompanyObserverProbe extends CompanyObserver
{
    public array $configs = [];

    protected function createTransportConfig(Company $company): void
    {
        $this->configs[] = $company->uuid;
    }
}

class FleetOpsUserObserverProbe extends UserObserver
{
    public array $deleted = [];

    protected function deleteDrivers(string $userUuid): void
    {
        $this->deleted[] = $userUuid;
    }
}

class FleetOpsPayloadObserverPayloadFake extends Payload
{
    public int $updates = 0;

    public function updateOrderDistanceAndTime(): ?Order
    {
        $this->updates++;

        return null;
    }
}

class FleetOpsPurchaseRateObserverProbe extends PurchaseRateObserver
{
    public bool $loaded           = false;
    public ?Company $company      = null;
    public ?Order $order          = null;
    public array $currencyInputs  = [];
    public array $transactions    = [];
    public array $items           = [];
    public bool $hasQuote         = true;
    public ?string $quoteCurrency = null;
    public int|float $quoteAmount = 0;
    public Illuminate\Support\Collection $quoteItems;
    public string $transactionId = 'generated-transaction-id';

    public function __construct()
    {
        $this->quoteItems = collect();
    }

    protected function generateUuid(): string
    {
        return 'purchase-rate-uuid';
    }

    protected function loadRelations(PurchaseRate $purchaseRate): void
    {
        $this->loaded = true;
    }

    protected function findCompany(?string $uuid): ?Company
    {
        $this->currencyInputs[] = ['findCompany', $uuid];

        return $this->company;
    }

    protected function getCompanyTransactionCurrency(mixed $company): string
    {
        $this->currencyInputs[] = ['currency', $company instanceof Company ? $company->uuid : $company];

        return 'USD';
    }

    protected function getServiceQuoteCurrency(PurchaseRate $purchaseRate): ?string
    {
        return $this->quoteCurrency;
    }

    protected function getServiceQuoteAmount(PurchaseRate $purchaseRate): int|float
    {
        return $this->quoteAmount;
    }

    protected function getTransactionId(PurchaseRate $purchaseRate): string
    {
        return $this->transactionId;
    }

    protected function hasServiceQuote(PurchaseRate $purchaseRate): bool
    {
        return $this->hasQuote;
    }

    protected function getServiceQuoteItems(PurchaseRate $purchaseRate): Illuminate\Support\Collection
    {
        return $this->quoteItems;
    }

    protected function createTransaction(array $attributes): Transaction
    {
        $this->transactions[] = $attributes;

        $transaction = new Transaction();
        $transaction->setRawAttributes(['uuid' => 'transaction-uuid'], true);

        return $transaction;
    }

    protected function createTransactionItem(array $attributes): TransactionItem
    {
        $this->items[] = $attributes;

        return new TransactionItem();
    }

    protected function resolveOrder(PurchaseRate $purchaseRate): ?Order
    {
        return $this->order;
    }
}

test('work order observer creates maintenance resets schedule and dispatches completed event on close', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-01 12:00:00'));

    $schedule = new FleetOpsWorkOrderObserverScheduleFake();
    $schedule->setRawAttributes(['uuid' => 'schedule-uuid']);

    $workOrder = new FleetOpsWorkOrderObserverWorkOrderFake();
    $workOrder->setRawAttributes([
        'uuid'            => 'work-order-uuid',
        'company_uuid'    => 'company-uuid',
        'status'          => 'closed',
        'target_type'     => 'fleet-ops:vehicle',
        'target_uuid'     => 'vehicle-uuid',
        'priority'        => 'high',
        'opened_at'       => Carbon::parse('2026-03-01 09:00:00'),
        'closed_at'       => Carbon::parse('2026-03-05 10:30:00'),
        'assignee_type'   => 'fleet-ops:contact',
        'assignee_uuid'   => 'assignee-uuid',
        'subject'         => 'Replace brakes',
        'created_by_uuid' => 'creator-uuid',
        'schedule_uuid'   => 'schedule-uuid',
        'meta'            => [
            'completion_data' => [
                'notes'        => 'Completed cleanly',
                'odometer'     => '12000',
                'engine_hours' => '550',
                'labor_cost'   => 1000,
                'parts_cost'   => 2500,
                'tax'          => 300,
                'total_cost'   => 3800,
                'currency'     => 'USD',
                'line_items'   => [['label' => 'Brake pads']],
            ],
        ],
    ]);

    $observer           = new FleetOpsWorkOrderObserverProbe();
    $observer->schedule = $schedule;

    $observer->updated($workOrder);

    expect($observer->createdMaintenance)->toHaveCount(1)
        ->and($observer->createdMaintenance[0])->toMatchArray([
            'company_uuid'      => 'company-uuid',
            'work_order_uuid'   => 'work-order-uuid',
            'maintainable_type' => 'fleet-ops:vehicle',
            'maintainable_uuid' => 'vehicle-uuid',
            'type'              => 'scheduled',
            'status'            => 'done',
            'priority'          => 'high',
            'performed_by_type' => 'fleet-ops:contact',
            'performed_by_uuid' => 'assignee-uuid',
            'summary'           => 'Replace brakes',
            'notes'             => 'Completed cleanly',
            'odometer'          => '12000',
            'engine_hours'      => '550',
            'total_cost'        => 3800,
            'created_by_uuid'   => 'creator-uuid',
        ])
        ->and($observer->createdMaintenance[0]['completed_at']->toDateTimeString())->toBe('2026-03-05 10:30:00')
        ->and($schedule->resets)->toHaveCount(1)
        ->and($schedule->resets[0][0])->toBe(12000)
        ->and($schedule->resets[0][1])->toBe(550)
        ->and($schedule->resets[0][2]->toDateTimeString())->toBe('2026-03-05 10:30:00')
        ->and($observer->completedEvents)->toBe([$workOrder]);

    Carbon::setTestNow();
});

test('purchase rate observer creates transaction items and defaults generated attributes', function () {
    session(['company' => 'session-company-uuid']);

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-uuid'], true);

    $purchaseRate = new PurchaseRate();
    $purchaseRate->setRawAttributes([
        'company_uuid'   => 'purchase-company-uuid',
        'customer_uuid'  => 'customer-uuid',
        'customer_type'  => 'fleet-ops:contact',
        'payload_uuid'   => 'payload-uuid',
        'meta'           => ['transaction_id' => 'gateway-id'],
        'status'         => null,
    ], true);

    $company = new Company();
    $company->setRawAttributes(['uuid' => 'session-company-uuid'], true);

    $observer                = new FleetOpsPurchaseRateObserverProbe();
    $observer->order         = $order;
    $observer->company       = $company;
    $observer->quoteAmount   = 4550;
    $observer->transactionId = 'gateway-id';
    $observer->quoteItems    = collect([
        (object) ['amount' => 1200, 'details' => 'Base rate', 'code' => 'base'],
        (object) ['amount' => null],
    ]);

    $observer->creating($purchaseRate);

    expect($observer->loaded)->toBeTrue()
        ->and($purchaseRate->uuid)->toBe('purchase-rate-uuid')
        ->and($purchaseRate->transaction_uuid)->toBe('transaction-uuid')
        ->and($purchaseRate->status)->toBe(Transaction::STATUS_SUCCESS)
        ->and($observer->currencyInputs)->toBe([
            ['findCompany', 'session-company-uuid'],
            ['currency', 'session-company-uuid'],
        ])
        ->and($observer->transactions[0])->toMatchArray([
            'company_uuid'           => 'session-company-uuid',
            'customer_uuid'          => 'customer-uuid',
            'customer_type'          => 'fleet-ops:contact',
            'subject_uuid'           => 'order-uuid',
            'subject_type'           => Order::class,
            'context_uuid'           => 'purchase-rate-uuid',
            'context_type'           => PurchaseRate::class,
            'gateway_transaction_id' => 'gateway-id',
            'gateway'                => 'internal',
            'amount'                 => 4550,
            'currency'               => 'USD',
            'type'                   => 'dispatch',
            'direction'              => Transaction::DIRECTION_CREDIT,
            'status'                 => Transaction::STATUS_SUCCESS,
            'settlement_status'      => Transaction::SETTLEMENT_STATUS_UNPAID,
        ])
        ->and($observer->items)->toBe([
            [
                'transaction_uuid' => 'transaction-uuid',
                'amount'           => 1200,
                'currency'         => 'USD',
                'details'          => 'Base rate',
                'code'             => 'base',
            ],
            [
                'transaction_uuid' => 'transaction-uuid',
                'amount'           => 0,
                'currency'         => 'USD',
                'details'          => 'Internal dispatch',
                'code'             => 'internal',
            ],
        ]);
});

test('purchase rate observer uses service quote currency and handles missing order or quote items', function () {
    session(['company' => null]);

    $purchaseRate = new PurchaseRate();
    $purchaseRate->setRawAttributes([
        'uuid'          => 'existing-purchase-rate',
        'company_uuid'  => 'purchase-company-uuid',
        'payload_uuid'  => null,
        'status'        => 'pending',
    ], true);

    $observer                = new FleetOpsPurchaseRateObserverProbe();
    $observer->quoteAmount   = 250;
    $observer->quoteCurrency = 'SGD';
    $observer->quoteItems    = collect();

    $observer->creating($purchaseRate);

    expect($purchaseRate->uuid)->toBe('existing-purchase-rate')
        ->and($purchaseRate->status)->toBe('pending')
        ->and($observer->currencyInputs)->toBe([['findCompany', 'purchase-company-uuid']])
        ->and($observer->transactions[0])->toMatchArray([
            'company_uuid'  => 'purchase-company-uuid',
            'subject_uuid'  => null,
            'subject_type'  => null,
            'context_uuid'  => 'existing-purchase-rate',
            'amount'        => 250,
            'currency'      => 'SGD',
        ])
        ->and($observer->items)->toBe([]);
});

test('place observer uppercases address fields and invalidates live places cache', function () {
    Cache::swap(new Repository(new ArrayStore()));
    session(['company' => 'company-uuid']);

    $place               = new Place();
    $place->name         = 'central depot';
    $place->street1      = 'main road';
    $place->city         = 'singapore';
    $place->country      = 'sg';
    $place->postal_code  = 12345;
    $place->neighborhood = null;

    $observer = new PlaceObserver();
    $observer->creating($place);
    $observer->created($place);
    $observer->updated($place);
    $observer->deleted($place);

    expect($place->name)->toBe('CENTRAL DEPOT')
        ->and($place->street1)->toBe('MAIN ROAD')
        ->and($place->city)->toBe('SINGAPORE')
        ->and($place->country)->toBe('SG')
        ->and($place->postal_code)->toBe(12345)
        ->and(Cache::get('live:company-uuid:places:version'))->toBe(3);
});

test('fleet service area company category user and payload observers delegate lifecycle side effects', function () {
    Cache::swap(new Repository(new ArrayStore()));
    session(['company' => 'company-uuid']);

    $fleet = new Fleet();
    $fleet->setRawAttributes(['uuid' => 'fleet-uuid'], true);

    $fleetObserver = new FleetOpsFleetObserverProbe();
    $fleetObserver->created($fleet);
    $fleetObserver->updated($fleet);
    $fleetObserver->deleted($fleet);

    expect($fleetObserver->cleared)->toBe(['fleet-uuid'])
        ->and(Cache::get('live:company-uuid:operations-monitor:version'))->toBe(3);

    $serviceArea          = new FleetOpsServiceAreaObserverServiceAreaFake();
    $serviceArea->country = 'SG';
    $serviceArea->setRelation('zones', collect(['zone-a', 'zone-b']));

    $serviceAreaObserver = new FleetOpsServiceAreaObserverProbe();
    $serviceAreaObserver->creating($serviceArea);
    $serviceAreaObserver->deleted($serviceArea);

    expect($serviceArea->border)->toBe('polygon-SG')
        ->and($serviceAreaObserver->countries)->toBe(['SG'])
        ->and($serviceAreaObserver->deleted)->toHaveCount(1);

    $withBorder          = new FleetOpsServiceAreaObserverServiceAreaFake();
    $withBorder->border  = 'existing-border';
    $withBorder->country = 'MY';
    $serviceAreaObserver->creating($withBorder);

    expect($withBorder->border)->toBe('existing-border')
        ->and($serviceAreaObserver->countries)->toBe(['SG']);

    $companyUser = new FleetOpsCompanyUserFake();
    $companyUser->setRawAttributes([
        'user_uuid' => 'user-uuid',
        'status'    => 'inactive',
    ], true);

    $driver                      = new FleetOpsObserverDriverFake();
    $companyUserObserver         = new FleetOpsCompanyUserObserverProbe();
    $companyUserObserver->driver = $driver;
    $companyUserObserver->deleted($companyUser);
    $companyUserObserver->updated($companyUser);

    expect($companyUserObserver->deleted)->toBe(['user-uuid'])
        ->and($driver->updates)->toBe([['status' => 'inactive']]);

    $companyUser->statusChanged = false;
    $companyUserObserver->updated($companyUser);

    expect($driver->updates)->toHaveCount(1);

    $category = new Category();
    $category->setRawAttributes(['uuid' => 'category-uuid'], true);
    $categoryObserver = new FleetOpsCategoryObserverProbe();
    $categoryObserver->deleted($category);

    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-uuid'], true);
    $companyObserver = new FleetOpsCompanyObserverProbe();
    $companyObserver->created($company);

    $user = new User();
    $user->setRawAttributes(['uuid' => 'user-uuid'], true);
    $userObserver = new FleetOpsUserObserverProbe();
    $userObserver->deleted($user);

    $payload = new FleetOpsPayloadObserverPayloadFake();
    (new PayloadObserver())->created($payload);

    expect($categoryObserver->deleted)->toBe(['category-uuid'])
        ->and($companyObserver->configs)->toBe(['company-uuid'])
        ->and($userObserver->deleted)->toBe(['user-uuid'])
        ->and($payload->updates)->toBe(1);
});

test('work order observer skips non closing duplicate and missing schedule branches', function () {
    $observer = new FleetOpsWorkOrderObserverProbe();

    $unchanged = new FleetOpsWorkOrderObserverWorkOrderFake();
    $unchanged->setRawAttributes(['status' => 'closed']);
    $unchanged->statusWasChanged = false;
    $observer->updated($unchanged);

    $open = new FleetOpsWorkOrderObserverWorkOrderFake();
    $open->setRawAttributes(['status' => 'open']);
    $observer->updated($open);

    expect($observer->createdMaintenance)->toBe([])
        ->and($observer->completedEvents)->toBe([]);

    $duplicate = new FleetOpsWorkOrderObserverWorkOrderFake();
    $duplicate->setRawAttributes([
        'uuid'          => 'duplicate-work-order',
        'status'        => 'closed',
        'schedule_uuid' => 'missing-schedule',
    ]);
    $observer->maintenanceExists = true;
    $observer->updated($duplicate);

    expect($observer->createdMaintenance)->toBe([])
        ->and($observer->completedEvents)->toBe([$duplicate]);
});

test('order observer starts dispatched orders and invalidates live cache entries', function () {
    Cache::swap(new Repository(new ArrayStore()));
    session(['company' => 'company-uuid']);
    Carbon::setTestNow(Carbon::parse('2026-04-02 08:15:00'));

    $order               = new FleetOpsOrderObserverOrderFake();
    $order->uuid         = 'order-uuid';
    $order->status       = 'started';
    $order->started      = false;
    $order->started_at   = null;
    $order->originalData = ['status' => 'dispatched'];
    $order->statusDirty  = true;

    $observer = new OrderObserver();

    $observer->updating($order);
    $observer->created($order);

    expect($order->started)->toBeTrue()
        ->and($order->started_at->toDateTimeString())->toBe('2026-04-02 08:15:00')
        ->and(Cache::get('live:company-uuid:orders:version'))->toBe(1)
        ->and(Cache::get('live:company-uuid:routes:version'))->toBe(1)
        ->and(Cache::get('live:company-uuid:coordinates:version'))->toBe(1);

    Carbon::setTestNow();
});

test('order observer preserves explicit start fields and notifies assigned driver on update', function () {
    Cache::swap(new Repository(new ArrayStore()));
    session(['company' => 'company-uuid']);

    $explicitStart            = Carbon::parse('2026-04-01 10:00:00');
    $order                    = new FleetOpsOrderObserverOrderFake();
    $order->uuid              = 'order-update-uuid';
    $order->status            = 'started';
    $order->started           = true;
    $order->started_at        = $explicitStart;
    $order->originalData      = ['status' => 'dispatched'];
    $order->statusDirty       = true;
    $order->driverWasChanged  = true;

    $observer = new OrderObserver();

    $observer->updating($order);
    $observer->updated($order);

    expect($order->started)->toBeTrue()
        ->and($order->started_at)->toBe($explicitStart)
        ->and($order->events)->toBe([
            'setDriverLocationAsPickup',
            'notifyDriverAssigned',
        ])
        ->and(Cache::get('live:company-uuid:orders:version'))->toBe(1);
});

test('order observer ignores non dispatched start transitions', function () {
    $order               = new FleetOpsOrderObserverOrderFake();
    $order->status       = 'started';
    $order->started      = false;
    $order->started_at   = null;
    $order->originalData = ['status' => 'created'];
    $order->statusDirty  = true;

    (new OrderObserver())->updating($order);

    expect($order->started)->toBeFalse()
        ->and($order->started_at)->toBeNull();
});

test('contact observer creates syncs normalizes and deletes associated users', function () {
    $observer = new ContactObserver();
    $contact  = new FleetOpsContactObserverContactFake();
    $contact->setRawAttributes(['type' => 'customer']);
    $contact->customer = true;

    $observer->creating($contact);
    $observer->saving($contact);
    $observer->deleted($contact);

    expect($contact->events)->toBe([
        'doesntHaveUser',
        'createUser',
        'assertCustomerIdentityIsAvailable',
        'doesntHaveUser',
        'isCustomer',
        'normalizeCustomerUser',
        'syncWithUser',
        'deleteUser',
    ]);
});

test('contact observer prevents changing existing customer contact type', function () {
    $observer              = new ContactObserver();
    $contact               = new FleetOpsContactObserverContactFake();
    $contact->exists       = true;
    $contact->typeDirty    = true;
    $contact->originalData = ['type' => 'customer'];
    $contact->setRawAttributes(['type' => 'driver']);

    expect(fn () => $observer->saving($contact))
        ->toThrow(Exception::class, 'Customer contact type cannot be changed.');
});

test('driver observer defaults location unassigns related records and deletes driver users', function () {
    $driver = new Driver();
    $driver->setRawAttributes([
        'uuid'      => 'driver-uuid',
        'user_uuid' => 'user-uuid',
    ], true);

    $observer = new FleetOpsDriverObserverProbe();
    $user     = new FleetOpsDriverObserverUserFake();

    $observer->user = $user;
    $observer->creating($driver);
    $observer->created($driver);
    $observer->updated($driver);
    $observer->deleting($driver);
    $observer->deleted($driver);

    expect($driver->location)->toBeInstanceOf(Fleetbase\LaravelMysqlSpatial\Types\Point::class)
        ->and($driver->vehicle_uuid)->toBeNull()
        ->and($observer->invalidations)->toBe([
            ['drivers', 'operations-monitor'],
            ['drivers', 'operations-monitor'],
            ['drivers', 'operations-monitor'],
        ])
        ->and($observer->unassigned)->toBe(['driver-uuid'])
        ->and($user->deleted)->toBeTrue();

    $driverWithLocation           = new Driver();
    $location                     = new Fleetbase\LaravelMysqlSpatial\Types\Point(1.3, 103.8);
    $driverWithLocation->location = $location;
    $observer->creating($driverWithLocation);

    expect($driverWithLocation->location)->toBe($location);
});

test('vehicle observer assigns requested driver and invalidates caches', function () {
    $observer             = new FleetOpsVehicleObserverProbe();
    $observer->identifier = 'driver-uuid';
    $observer->driver     = new FleetOpsVehicleObserverDriverFake();

    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-uuid'], true);

    $observer->created($vehicle);
    $observer->updating($vehicle);
    $observer->deleted($vehicle);

    expect($observer->driver->assignments)->toBe([$vehicle, $vehicle])
        ->and($vehicle->getRelation('driver'))->toBe($observer->driver)
        ->and($observer->deleted)->toBe(['vehicle-uuid'])
        ->and($observer->invalidated)->toBe([
            ['vehicles', 'operations-monitor'],
            ['vehicles', 'operations-monitor'],
            ['vehicles', 'operations-monitor'],
        ]);

    $withoutDriver = new FleetOpsVehicleObserverProbe();
    $withoutDriver->created(new Vehicle());

    expect($withoutDriver->invalidated)->toBe([['vehicles', 'operations-monitor']]);
});

test('service rate observer syncs rate and parcel fee inputs and deletes loaded fees', function () {
    $observer                  = new FleetOpsServiceRateObserverProbe();
    $observer->rateFeesInput   = [['fee' => 10]];
    $observer->parcelFeesInput = [['parcel_fee' => 20]];

    $serviceRate                = new FleetOpsServiceRateObserverServiceRateFake();
    $serviceRate->fixedMeter    = true;
    $serviceRate->parcelService = true;

    $observer->created($serviceRate);

    expect($serviceRate->rateFeeCalls)->toBe([[['fee' => 10]]])
        ->and($serviceRate->parcelFeeCalls)->toBe([[['parcel_fee' => 20]]]);

    $serviceRate->fixedMeter       = false;
    $serviceRate->perDrop          = true;
    $serviceRate->multiZone        = false;
    $serviceRate->parcelService    = false;
    $serviceRate->rateFeeCalls     = [];
    $serviceRate->parcelFeeCalls   = [];
    $observer->updated($serviceRate);

    expect($serviceRate->rateFeeCalls)->toBe([[['fee' => 10]]])
        ->and($serviceRate->parcelFeeCalls)->toBe([]);

    $observer->deleted($serviceRate);

    expect($serviceRate->relationsLoaded)->toBeTrue()
        ->and($observer->deletedModelBatches)->toHaveCount(2);
});

test('tracking number observer generates codes and creates initial tracking status', function () {
    session(['company' => 'company-uuid']);

    $trackingNumber = new FleetOpsTrackingNumberObserverTrackingNumberFake();
    $trackingNumber->setRawAttributes([
        'uuid'       => 'tracking-uuid',
        'region'     => 'sg',
        'owner_uuid' => 'owner-uuid',
        'owner_type' => Order::class,
    ], true);

    $observer = new FleetOpsTrackingNumberObserverProbe();
    $observer->creating($trackingNumber);
    $observer->created($trackingNumber);

    expect($trackingNumber->tracking_number)->toBe('TN-sg')
        ->and($trackingNumber->qr_code)->toBe('QRCODE-png')
        ->and($trackingNumber->barcode)->toBe('PDF417-png')
        ->and($observer->barcodes)->toBe([
            ['owner-uuid', 'QRCODE'],
            ['owner-uuid', 'PDF417'],
        ])
        ->and($observer->statuses[0])->toMatchArray([
            'company_uuid'         => 'company-uuid',
            'tracking_number_uuid' => 'tracking-uuid',
            'status'               => 'Order Created',
            'details'              => 'New order created.',
            'code'                 => 'CREATED',
        ])
        ->and($observer->statuses[0]['location'])->toBeInstanceOf(Fleetbase\LaravelMysqlSpatial\Types\Point::class)
        ->and($trackingNumber->ownerStatuses)->toHaveCount(1);
});

test('zone observer invalidates service area cache for lifecycle events and original service area', function () {
    $zone = new Zone();
    $zone->setRawAttributes([
        'uuid'              => 'zone-uuid',
        'company_uuid'      => 'company-uuid',
        'service_area_uuid' => 'service-area-old',
    ], true);
    $zone->service_area_uuid = 'service-area-new';

    $observer = new FleetOpsZoneObserverProbe();
    $observer->created($zone);
    $observer->updated($zone);
    $observer->deleted($zone);
    $observer->restored($zone);
    $observer->created(new Zone());

    expect($observer->invalidations)->toBe([
        ['company-uuid', 'service-area-new'],
        ['company-uuid', 'service-area-old'],
        ['company-uuid', 'service-area-new'],
        ['company-uuid', 'service-area-new'],
        ['company-uuid', 'service-area-new'],
    ]);
});

test('notify driver on shift change exits early and sends enabled driver notifications', function () {
    $listener = new FleetOpsNotifyDriverOnShiftChangeProbe();

    $listener->handle((object) []);
    expect($listener->sent)->toBe([]);

    $scheduleItem = new ScheduleItem();
    $listener->handle((object) ['scheduleItem' => $scheduleItem]);
    expect($listener->sent)->toBe([]);

    $schedule = new Schedule();
    $schedule->setRelation('subject', new User());
    $listener->schedule = $schedule;
    $listener->handle((object) ['scheduleItem' => $scheduleItem]);
    expect($listener->sent)->toBe([]);

    $driver = new Driver();
    $schedule->setRelation('subject', $driver);
    $listener->handle((object) ['scheduleItem' => $scheduleItem]);
    expect($listener->sent)->toBe([]);

    $listener->settings     = ['notify_drivers_on_shift_change' => true];
    $listener->createdEvent = true;
    $listener->handle((object) ['scheduleItem' => $scheduleItem]);

    expect($listener->sent)->toHaveCount(1)
        ->and($listener->sent[0][0])->toBe($driver)
        ->and($listener->sent[0][1])->toBeInstanceOf(DriverShiftChanged::class);
});

if (!function_exists('Fleetbase\FleetOps\Observers\event')) {
    eval('namespace Fleetbase\FleetOps\Observers; function event($event = null, $payload = null) { $GLOBALS["fleetopsObserverEvents"][] = [$event, $payload]; return []; }');
}

test('work order observer real helpers query maintenance schedules and events', function () {
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
    if (!Illuminate\Database\Eloquent\Model::getEventDispatcher()) {
        Illuminate\Database\Eloquent\Model::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }
    $schema = $connection->getSchemaBuilder();
    foreach (['maintenances' => ['uuid', 'public_id', 'company_uuid', 'work_order_uuid', 'maintainable_type', 'maintainable_uuid', 'type', 'status', 'scheduled_at', 'odometer', 'engine_hours', 'summary', '_key'], 'maintenance_schedules' => ['uuid', 'public_id', 'company_uuid', 'name', 'next_due_at', 'meta', '_key']] as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }
    session(['company' => 'company-1']);
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());
    $connection->table('maintenances')->insert(['uuid' => 'mnt-obs-1', 'work_order_uuid' => 'wo-obs-1', 'status' => 'completed']);
    $connection->table('maintenance_schedules')->insert(['uuid' => 'sched-obs-1', 'company_uuid' => 'company-1', 'name' => 'Quarterly']);

    $observer = new WorkOrderObserver();
    $helper   = function (string $method, ...$arguments) use ($observer) {
        $reflection = new ReflectionMethod(WorkOrderObserver::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($observer, ...$arguments);
    };

    $workOrder = new WorkOrder();
    $workOrder->setRawAttributes(['uuid' => 'wo-obs-1', 'company_uuid' => 'company-1'], true);

    // Real queries scope by work order and schedule identity
    expect($helper('hasMaintenanceRecord', $workOrder))->toBeTrue()
        ->and($helper('findSchedule', 'sched-obs-1'))->toBeInstanceOf(MaintenanceSchedule::class)
        ->and($helper('findSchedule', 'sched-missing'))->toBeNull();

    // Maintenance creation persists rows through the observer helper
    $created = $helper('createMaintenance', ['company_uuid' => 'company-1', 'work_order_uuid' => 'wo-obs-2', 'status' => 'scheduled', 'type' => 'inspection']);
    expect($created)->toBeInstanceOf(Maintenance::class)
        ->and($connection->table('maintenances')->count())->toBe(2);

    // Completed events dispatch through the observer event shim
    $GLOBALS['fleetopsObserverEvents'] = [];
    $helper('dispatchCompletedEvent', $workOrder);
    expect($GLOBALS['fleetopsObserverEvents'][0][0])->toBe('work_order.completed');

    // Schedule resets skip work orders without linked schedules
    $unlinked = new WorkOrder();
    $unlinked->setRawAttributes(['uuid' => 'wo-obs-3', 'company_uuid' => 'company-1', 'schedule_uuid' => null], true);
    $helper('resetSchedule', $unlinked);

    // And skip quietly when the linked schedule row is missing
    $orphaned = new WorkOrder();
    $orphaned->setRawAttributes(['uuid' => 'wo-obs-4', 'company_uuid' => 'company-1', 'schedule_uuid' => 'sched-missing'], true);
    $helper('resetSchedule', $orphaned);
    expect(true)->toBeTrue();
});
