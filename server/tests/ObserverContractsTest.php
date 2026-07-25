<?php

use Carbon\Carbon;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\FleetOps\Observers\ContactObserver;
use Fleetbase\FleetOps\Observers\OrderObserver;
use Fleetbase\FleetOps\Observers\WorkOrderObserver;
use Fleetbase\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
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
