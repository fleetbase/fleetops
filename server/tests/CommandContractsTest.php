<?php

use Carbon\Carbon;
use Fleetbase\FleetOps\Console\Commands\AssignCustomerRoles;
use Fleetbase\FleetOps\Console\Commands\AssignDriverRoles;
use Fleetbase\FleetOps\Console\Commands\AuditCustomerUserConflicts;
use Fleetbase\FleetOps\Console\Commands\DebugOrderTracker;
use Fleetbase\FleetOps\Console\Commands\DispatchAdhocOrders;
use Fleetbase\FleetOps\Console\Commands\DispatchOrders;
use Fleetbase\FleetOps\Console\Commands\FixCustomerCompanies;
use Fleetbase\FleetOps\Console\Commands\FixDriverCompanies;
use Fleetbase\FleetOps\Console\Commands\FixLegacyOrderConfigs;
use Fleetbase\FleetOps\Console\Commands\ProcessMaintenanceTriggers;
use Fleetbase\FleetOps\Console\Commands\PurgeUnpurchasedServiceQuotes;
use Fleetbase\FleetOps\Console\Commands\SendDriverNotification;
use Fleetbase\FleetOps\Console\Commands\SendMaintenanceReminders;
use Fleetbase\FleetOps\Console\Commands\SimulateOrderRouteNavigation;
use Fleetbase\FleetOps\Console\Commands\SyncTelematics;
use Fleetbase\FleetOps\Console\Commands\TestEmail;
use Fleetbase\FleetOps\Console\Commands\TrackOrderDistanceAndTime;
use Fleetbase\FleetOps\Contracts\TelematicProviderDescriptor;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\FleetOps\Notifications\OrderAssigned;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return false; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Console\Commands\config')) {
    eval('namespace Fleetbase\FleetOps\Console\Commands; function config($key = null, $default = null) { return $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Console\Commands\now')) {
    eval('namespace Fleetbase\FleetOps\Console\Commands; function now($timezone = null) { return \Carbon\Carbon::now($timezone); }');
}

class FleetOpsCommandCacheFake
{
    public function __construct(private FleetOpsCommandLockFake $lock)
    {
    }

    public function lock($key, $seconds)
    {
        return $this->lock;
    }

    public function forget($key): bool
    {
        return true;
    }
}

class FleetOpsCommandLockFake
{
    public bool $released = false;

    public function __construct(private bool $locked)
    {
    }

    public function get(): bool
    {
        return $this->locked;
    }

    public function release(): void
    {
        $this->released = true;
    }
}

class FleetOpsTelematicProviderRegistryFake extends TelematicProviderRegistry
{
    public function __construct(Illuminate\Support\Collection $providers)
    {
        $this->providers = $providers;
    }

    public function all(): Illuminate\Support\Collection
    {
        return $this->providers;
    }
}

class FleetOpsProcessMaintenanceTriggersProbe extends ProcessMaintenanceTriggers
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ProcessMaintenanceTriggers::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsProcessMaintenanceTriggersHandleFake extends ProcessMaintenanceTriggers
{
    public array $createdWorkOrders = [];
    public array $dispatchedEvents  = [];
    public array $messages          = [];
    public array $operations        = [];
    public Illuminate\Support\Collection $testSchedules;
    public bool $existingOpen  = false;
    public int $workOrderCount = 0;

    public function __construct(private array $testOptions)
    {
        parent::__construct();
        $this->testSchedules = collect();
    }

    public function option($key = null)
    {
        return $key === null ? $this->testOptions : ($this->testOptions[$key] ?? null);
    }

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function line($string, $style = null, $verbosity = null)
    {
        $this->messages[] = ['line', $string];
    }

    protected function schedules(string $conn)
    {
        $this->operations[] = ['schedules', $conn];

        return $this->testSchedules;
    }

    protected function openWorkOrderExists(string $conn, MaintenanceSchedule $schedule): bool
    {
        $this->operations[] = ['openWorkOrderExists', $conn, $schedule->uuid];

        return $this->existingOpen;
    }

    protected function workOrderCount(string $conn): int
    {
        $this->operations[] = ['workOrderCount', $conn];

        return $this->workOrderCount;
    }

    protected function createWorkOrder(string $conn, array $attributes): WorkOrder
    {
        $this->operations[]        = ['createWorkOrder', $conn];
        $this->createdWorkOrders[] = $attributes;

        $workOrder = new WorkOrder();
        $workOrder->setRawAttributes(array_merge(['public_id' => 'work_order_created'], $attributes), true);

        return $workOrder;
    }

    protected function dispatchTriggeredEvent(MaintenanceSchedule $schedule, WorkOrder $workOrder): void
    {
        $this->dispatchedEvents[] = [$schedule->uuid, $workOrder->public_id];
    }
}

class FleetOpsSendMaintenanceRemindersCommandFake extends SendMaintenanceReminders
{
    public array $messages = [];
    public array $sent     = [];
    public array $records  = [];
    public Illuminate\Support\Collection $testSchedules;

    public function __construct(private array $testOptions, private array $alreadySent = [])
    {
        parent::__construct();
        $this->testSchedules = collect();
    }

    public function option($key = null)
    {
        return $key === null ? $this->testOptions : ($this->testOptions[$key] ?? null);
    }

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function line($string, $style = null, $verbosity = null)
    {
        $this->messages[] = ['line', $string];
    }

    protected function schedules(string $conn): Illuminate\Support\Collection
    {
        $this->messages[] = ['schedules', $conn];

        return $this->testSchedules;
    }

    protected function reminderAlreadySent(string $conn, MaintenanceSchedule $schedule, int $offsetDays, string $dueDateSnapshot): bool
    {
        $key = implode('|', [$conn, $schedule->uuid, $offsetDays, $dueDateSnapshot]);

        return in_array($key, $this->alreadySent, true);
    }

    protected function sendReminder(string $email, MaintenanceSchedule $schedule, int $offsetDays): void
    {
        $this->sent[] = [$email, $schedule->uuid, $offsetDays];
    }

    protected function recordReminder(string $conn, MaintenanceSchedule $schedule, int $offsetDays, string $dueDateSnapshot): void
    {
        $this->records[] = [$conn, $schedule->uuid, $offsetDays, $dueDateSnapshot];
    }
}

class FleetOpsSimulateOrderFake extends Order
{
    public ?Driver $assignedDriver = null;

    public function loadAssignedDriver(): Order
    {
        $this->setRelation('driverAssigned', $this->assignedDriver);

        return $this;
    }
}

class FleetOpsSimulateOrderRouteNavigationCommandFake extends SimulateOrderRouteNavigation
{
    public array $messages  = [];
    public array $events    = [];
    public int $pauses      = 0;
    public ?Order $order    = null;
    public ?Driver $driver  = null;
    public array $route     = [];
    public array $waypoints = [];

    public function __construct(private array $testArguments)
    {
        parent::__construct();
    }

    public function argument($key = null)
    {
        return $key === null ? $this->testArguments : ($this->testArguments[$key] ?? null);
    }

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function error($string, $verbosity = null)
    {
        $this->messages[] = ['error', $string];

        return Command::FAILURE;
    }

    public function promptQuestions(): array
    {
        return $this->promptForMissingArgumentsUsing();
    }

    protected function findOrder(string $orderId): ?Order
    {
        $this->messages[] = ['findOrder', $orderId];

        return $this->order;
    }

    protected function findDriver(string $driverId): ?Driver
    {
        $this->messages[] = ['findDriver', $driverId];

        return $this->driver;
    }

    protected function getRoute(mixed $start, mixed $end): array
    {
        $this->messages[] = ['getRoute', (string) $start, (string) $end];

        return $this->route;
    }

    protected function decodePolyline(string $routeGeometry): array
    {
        $this->messages[] = ['decodePolyline', $routeGeometry];

        return $this->waypoints;
    }

    protected function dispatchLocationChanged(Driver $driver, mixed $waypoint, array $additionalData): void
    {
        $this->events[] = [$driver->public_id, (string) $waypoint, $additionalData];
    }

    protected function pauseBetweenWaypoints(): void
    {
        $this->pauses++;
    }
}

class FleetOpsDispatchAdhocOrdersCommandFake extends DispatchAdhocOrders
{
    public array $messages = [];
    public array $tables   = [];
    public EloquentCollection $orders;
    public EloquentCollection $drivers;

    public function __construct(private array $testOptions)
    {
        parent::__construct();
        $this->orders  = new EloquentCollection();
        $this->drivers = new EloquentCollection();
    }

    public function option($key = null)
    {
        return $key === null ? $this->testOptions : ($this->testOptions[$key] ?? null);
    }

    public function getDispatchableOrders(int $days = 2, int $intervalMinutes = 4, int $expiryHours = 72): EloquentCollection
    {
        $this->messages[] = ['getDispatchableOrders', $days, $intervalMinutes, $expiryHours];

        return $this->orders;
    }

    public function getNearbyDriversForOrder(Order $order, Point $pickup, int $distance, bool $testing = false): EloquentCollection
    {
        $this->messages[] = ['getNearbyDriversForOrder', $order->public_id, $distance, $testing];

        return $this->drivers;
    }

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function alert($string, $verbosity = null)
    {
        $this->messages[] = ['alert', $string];
    }

    public function table($headers, $rows, $tableStyle = 'default', array $columnStyles = [])
    {
        $this->tables[] = [$headers, $rows];
    }

    public function error($string, $verbosity = null)
    {
        $this->messages[] = ['error', $string];
    }

    public function line($string, $style = null, $verbosity = null)
    {
        $this->messages[] = ['line', $string];
    }

    public function warn($string, $verbosity = null)
    {
        $this->messages[] = ['warn', $string];
    }
}

class FleetOpsDispatchAdhocOrdersQueryProbe extends DispatchAdhocOrders
{
    public FleetOpsDispatchAdhocOrdersBuilderFake $orderQuery;
    public FleetOpsDispatchAdhocOrdersBuilderFake $driverQuery;

    public function __construct(private array $testOptions)
    {
        parent::__construct();
        $this->orderQuery  = new FleetOpsDispatchAdhocOrdersBuilderFake();
        $this->driverQuery = new FleetOpsDispatchAdhocOrdersBuilderFake();
    }

    public function option($key = null)
    {
        return $key === null ? $this->testOptions : ($this->testOptions[$key] ?? null);
    }

    protected function newOrderQuery(string $connection)
    {
        $this->orderQuery->calls[] = ['connection', $connection];

        return $this->orderQuery;
    }

    protected function newDriverQuery()
    {
        return $this->driverQuery;
    }
}

class FleetOpsDispatchAdhocOrdersBuilderFake
{
    public array $calls = [];
    public EloquentCollection $results;

    public function __construct()
    {
        $this->results = new EloquentCollection();
    }

    public function withoutGlobalScopes(): self
    {
        $this->calls[] = ['withoutGlobalScopes'];

        return $this;
    }

    public function where(...$arguments): self
    {
        $this->calls[] = ['where', $arguments];

        return $this;
    }

    public function whereBetween(string $column, array $range): self
    {
        $this->calls[] = ['whereBetween', $column, $range];

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->calls[] = ['whereNull', $column];

        return $this;
    }

    public function whereHas(string $relation, ?Closure $callback = null): self
    {
        $this->calls[] = ['whereHas', $relation];

        if ($callback) {
            $callback($this);
        }

        return $this;
    }

    public function with(array $relations): self
    {
        $this->calls[] = ['with', $relations];

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->calls[] = ['whereNotNull', $column];

        return $this;
    }

    public function whereRaw(string $sql): self
    {
        $this->calls[] = ['whereRaw', trim($sql)];

        return $this;
    }

    public function distanceSphere(string $column, Point $point, int $distance): self
    {
        $this->calls[] = ['distanceSphere', $column, $point, $distance];

        return $this;
    }

    public function distanceSphereValue(string $column, Point $point): self
    {
        $this->calls[] = ['distanceSphereValue', $column, $point];

        return $this;
    }

    public function orWhereHas(string $relation, ?Closure $callback = null): self
    {
        $this->calls[] = ['orWhereHas', $relation];

        if ($callback) {
            $callback($this);
        }

        return $this;
    }

    public function get(): EloquentCollection
    {
        return $this->results;
    }
}

class FleetOpsDispatchAdhocOrdersOrderFake extends Order
{
    public ?string $public_id      = null;
    public mixed $dispatched_at    = null;
    public ?string $company_uuid   = null;
    public bool $dispatchedForPing = false;
    public mixed $pickupLocation;
    public int $adhocDistance = 6000;

    public function getPickupLocation(): mixed
    {
        return $this->pickupLocation;
    }

    public function getAdhocPingDistance(): int
    {
        return $this->adhocDistance;
    }

    public function dispatch(bool $save = true): self
    {
        $this->dispatchedForPing = $save;

        return $this;
    }
}

class FleetOpsDispatchAdhocOrdersDriverFake extends Driver
{
    public ?string $public_id   = null;
    public ?string $name        = null;
    public array $notifications = [];

    public function notify($instance): void
    {
        $this->notifications[] = $instance::class;
    }
}

class FleetOpsTrackOrderDistanceAndTimeProbe extends TrackOrderDistanceAndTime
{
    public array $messages = [];
    public FleetOpsTrackOrderQueryFake $query;
    public FleetOpsTrackProgressBarFake $progressBar;

    public function __construct(private array $testOptions, array $orders = [])
    {
        parent::__construct();
        $this->query       = new FleetOpsTrackOrderQueryFake($orders);
        $this->progressBar = new FleetOpsTrackProgressBarFake();
    }

    public function option($key = null)
    {
        return $key === null ? $this->testOptions : ($this->testOptions[$key] ?? null);
    }

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function warn($string, $verbosity = null)
    {
        $this->messages[] = ['warn', $string];
    }

    public function error($string, $verbosity = null)
    {
        $this->messages[] = ['error', $string];
    }

    public function alert($string, $verbosity = null)
    {
        $this->messages[] = ['alert', $string];
    }

    public function newLine($count = 1)
    {
        $this->messages[] = ['newLine', $count];
    }

    protected function activeOrdersQuery(Carbon $cutoff)
    {
        $this->messages[] = ['activeOrdersQuery', $cutoff->toDateTimeString()];

        return $this->query;
    }

    protected function createProgressBar(int $total)
    {
        $this->messages[] = ['createProgressBar', $total];

        return $this->progressBar;
    }
}

class FleetOpsTrackProgressBarFake
{
    public int $started  = 0;
    public int $advanced = 0;
    public int $finished = 0;

    public function start(): void
    {
        $this->started++;
    }

    public function advance(): void
    {
        $this->advanced++;
    }

    public function finish(): void
    {
        $this->finished++;
    }
}

class FleetOpsTrackOrderQueryFake
{
    public array $calls = [];

    public function __construct(private array $orders)
    {
    }

    public function __clone()
    {
        $this->calls[] = ['clone'];
    }

    public function count($columns = '*'): int
    {
        $this->calls[] = ['count', $columns];

        return count($this->orders);
    }

    public function orderBy(string $column): self
    {
        $this->calls[] = ['orderBy', $column];

        return $this;
    }

    public function chunkById(int $perChunk, Closure $callback): void
    {
        $this->calls[] = ['chunkById', $perChunk];

        $callback(new FleetOpsTrackOrderChunkFake($this->orders));
    }
}

class FleetOpsFixLegacyOrderConfigsCommandFake extends FixLegacyOrderConfigs
{
    public array $messages = [];
    public array $created  = [];
    public array $configs  = [];
    public Illuminate\Support\Collection $testCompanies;
    public Illuminate\Support\Collection $testOrders;
    public FleetOpsTrackProgressBarFake $progressBar;

    public function __construct(private bool $createConfigs)
    {
        parent::__construct();
        $this->testCompanies = collect();
        $this->testOrders    = collect();
        $this->progressBar   = new FleetOpsTrackProgressBarFake();
    }

    public function option($key = null)
    {
        $options = ['create-configs' => $this->createConfigs];

        return $key === null ? $options : ($options[$key] ?? null);
    }

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function line($string, $style = null, $verbosity = null)
    {
        $this->messages[] = ['line', $string];
    }

    public function error($string, $verbosity = null)
    {
        $this->messages[] = ['error', $string];
    }

    protected function companies()
    {
        return $this->testCompanies;
    }

    protected function createTransportConfig(Company $company): void
    {
        $this->created[] = $company->uuid;
    }

    protected function ordersWithoutConfig()
    {
        return $this->testOrders;
    }

    protected function transportConfigForCompany(string $companyUuid): ?OrderConfig
    {
        return $this->configs[$companyUuid] ?? null;
    }

    protected function createProgressBar(int $total)
    {
        $this->messages[] = ['createProgressBar', $total];

        return $this->progressBar;
    }
}

class FleetOpsLegacyOrderFake extends Order
{
    public array $updates   = [];
    public bool $failUpdate = false;

    public function update(array $attributes = [], array $options = [])
    {
        if ($this->failUpdate) {
            throw new RuntimeException('order update failed');
        }

        $this->updates[] = $attributes;

        return true;
    }
}

class FleetOpsFixCustomerCompaniesCommandFake extends FixCustomerCompanies
{
    public array $messages      = [];
    public array $users         = [];
    public array $missing       = [];
    public array $companies     = [];
    public array $customerUsers = [];
    public Illuminate\Support\Collection $testCustomers;

    public function __construct()
    {
        parent::__construct();
        $this->testCustomers = collect();
    }

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function line($string, $style = null, $verbosity = null)
    {
        $this->messages[] = ['line', $string];
    }

    public function error($string, $verbosity = null)
    {
        $this->messages[] = ['error', $string];
    }

    protected function customers()
    {
        return $this->testCustomers;
    }

    protected function customerUser(Contact $customer)
    {
        return $this->customerUsers[spl_object_id($customer)] ?? null;
    }

    protected function createUserForCustomer(Contact $customer): mixed
    {
        if ($customer->failCreate ?? false) {
            throw new RuntimeException('existing user');
        }

        $this->customerUsers[spl_object_id($customer)] = $customer->createdUser;

        return $customer->createdUser;
    }

    protected function assignExistingUserToCustomer(Contact $customer, $existingUser): void
    {
        $customer->updateQuietly(['user_uuid' => $existingUser->uuid]);
        $this->customerUsers[spl_object_id($customer)] = $existingUser;
    }

    protected function userByEmail(string $email)
    {
        return $this->users[$email] ?? null;
    }

    protected function missingCompanyUser(string $userUuid, string $companyUuid): bool
    {
        return $this->missing[$userUuid . ':' . $companyUuid] ?? false;
    }

    protected function companyByUuid(string $companyUuid): ?Company
    {
        return $this->companies[$companyUuid] ?? null;
    }
}

class FleetOpsFixDriverCompaniesCommandFake extends FixDriverCompanies
{
    public array $messages  = [];
    public array $missing   = [];
    public array $companies = [];
    public Illuminate\Support\Collection $testDrivers;

    public function __construct()
    {
        parent::__construct();
        $this->testDrivers = collect();
    }

    public function line($string, $style = null, $verbosity = null)
    {
        $this->messages[] = ['line', $string];
    }

    protected function drivers()
    {
        return $this->testDrivers;
    }

    protected function missingCompanyUser(string $userUuid, string $companyUuid): bool
    {
        return $this->missing[$userUuid . ':' . $companyUuid] ?? false;
    }

    protected function companyByUuid(string $companyUuid): ?Company
    {
        return $this->companies[$companyUuid] ?? null;
    }
}

class FleetOpsUserCommandFake
{
    public string $uuid;
    public string $name;
    public string $email;
    public array $synced   = [];
    public array $assigned = [];

    public function __construct(string $uuid, string $name, string $email)
    {
        $this->uuid  = $uuid;
        $this->name  = $name;
        $this->email = $email;
    }

    public function syncProperty(string $property, Model $model): bool
    {
        $this->synced[] = [$property, $model::class];

        return true;
    }

    public function assignCompany(Company $company, string $role = 'Administrator'): self
    {
        $this->assigned[] = [$company->uuid, $role];

        return $this;
    }
}

class FleetOpsCustomerCommandFake extends Contact
{
    public mixed $createdUser  = null;
    public bool $failCreate    = false;
    public array $quietUpdates = [];

    public function loadMissing($relations)
    {
        return $this;
    }

    public function updateQuietly(array $attributes = [], array $options = [])
    {
        $this->quietUpdates[] = $attributes;

        return true;
    }
}

class FleetOpsPurgeServiceQuotesCommandFake extends PurgeUnpurchasedServiceQuotes
{
    public array $messages     = [];
    public array $events       = [];
    public int $deletedCount   = 0;
    public ?Throwable $failure = null;

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function error($string, $verbosity = null)
    {
        $this->messages[] = ['error', $string];
    }

    protected function disableForeignKeyConstraints(): void
    {
        $this->events[] = 'disable';
    }

    protected function enableForeignKeyConstraints(): void
    {
        $this->events[] = 'enable';
    }

    protected function beginTransaction(): void
    {
        $this->events[] = 'begin';
    }

    protected function commit(): void
    {
        $this->events[] = 'commit';
    }

    protected function rollBack(): void
    {
        $this->events[] = 'rollback';
    }

    protected function purgeServiceQuotes($thresholdDate): int
    {
        $this->events[] = ['purge', $thresholdDate->copy()];

        if ($this->failure) {
            throw $this->failure;
        }

        return $this->deletedCount;
    }
}

class FleetOpsSendDriverNotificationCommandFake extends SendDriverNotification
{
    public array $messages  = [];
    public array $questions = [];
    public array $choices   = [];
    public array $sent      = [];
    public ?Order $order    = null;
    public object $matrix;

    public function __construct(private array $testOptions)
    {
        parent::__construct();
        $this->matrix = (object) ['distance' => 1234];
    }

    public function option($key = null)
    {
        return $key === null ? $this->testOptions : ($this->testOptions[$key] ?? null);
    }

    public function ask($question, $default = null)
    {
        $this->questions[] = [$question, $default];

        return 'asked_order';
    }

    public function choice($question, array $choices, $default = null, $attempts = null, $multiple = false)
    {
        $this->choices[] = [$question, $choices, $default, $attempts, $multiple];

        return 'assigned';
    }

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function error($string, $verbosity = null)
    {
        $this->messages[] = ['error', $string];
    }

    protected function findOrder(string $orderId): ?Order
    {
        $this->messages[] = ['findOrder', $orderId];

        return $this->order;
    }

    protected function calculateDrivingDistanceAndTime(mixed $origin, mixed $destination): object
    {
        $this->messages[] = ['distance', $origin, $destination];

        return $this->matrix;
    }

    protected function notifyDriver($driver, string $notificationClass, Order $order, mixed $distance = null): void
    {
        $this->sent[] = [$driver, $notificationClass, $order->public_id, $distance];
    }
}

class FleetOpsNotificationDriverCommandFake extends Driver
{
    public array $notifications   = [];
    public mixed $locationForTest = null;

    public function getAttribute($key)
    {
        if ($key === 'location') {
            return $this->locationForTest;
        }

        return parent::getAttribute($key);
    }

    public function notify($instance): void
    {
        $this->notifications[] = $instance;
    }
}

class FleetOpsNotificationOrderCommandFake extends Order
{
    public string $publicIdForTest      = 'order_public';
    public mixed $driverAssignedForTest = null;
    public mixed $payloadForTest        = null;

    public function loadMissing($relations)
    {
        return $this;
    }

    public function getAttribute($key)
    {
        return match ($key) {
            'public_id'      => $this->publicIdForTest,
            'driverAssigned' => $this->driverAssignedForTest,
            'payload'        => $this->payloadForTest,
            default          => parent::getAttribute($key),
        };
    }
}

class FleetOpsNotificationPayloadCommandFake
{
    public function __construct(private mixed $pickup)
    {
    }

    public function getPickupOrFirstWaypoint(): mixed
    {
        return $this->pickup;
    }
}

class FleetOpsDispatchOrdersCommandFake extends DispatchOrders
{
    public array $messages = [];
    public EloquentCollection $orders;

    public function __construct(private array $testOptions)
    {
        parent::__construct();
        $this->orders = new EloquentCollection();
    }

    public function option($key = null)
    {
        return $key === null ? $this->testOptions : ($this->testOptions[$key] ?? null);
    }

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function alert($string, $verbosity = null)
    {
        $this->messages[] = ['alert', $string];
    }

    public function warn($string, $verbosity = null)
    {
        $this->messages[] = ['warn', $string];
    }

    protected function getScheduledOrders(bool $sandboxMode): EloquentCollection
    {
        $this->messages[] = ['getScheduledOrders', $sandboxMode];

        return $this->orders;
    }
}

class FleetOpsScheduledOrderCommandFake extends Order
{
    public string $publicIdForTest    = '';
    public string $scheduledAtForTest = '';
    public bool $ready                = false;
    public bool $dispatchedForTest    = false;

    public function getAttribute($key)
    {
        return match ($key) {
            'public_id'    => $this->publicIdForTest,
            'scheduled_at' => $this->scheduledAtForTest,
            default        => parent::getAttribute($key),
        };
    }

    public function shouldDispatch($precision = 1)
    {
        return $this->ready;
    }

    public function dispatch(bool $save = true): self
    {
        $this->dispatchedForTest = true;

        return $this;
    }
}

class FleetOpsAssignDriverRolesCommandFake extends AssignDriverRoles
{
    public array $messages = [];
    public Illuminate\Support\Collection $testCompanies;

    public function __construct()
    {
        parent::__construct();
        $this->testCompanies = collect();
    }

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function error($string, $verbosity = null)
    {
        $this->messages[] = ['error', $string];
    }

    protected function companies()
    {
        return $this->testCompanies;
    }

    protected function isUser($user): bool
    {
        return $user instanceof FleetOpsRoleUserCommandFake;
    }

    protected function setCompanyUserRelation($user, Company $company): void
    {
        $user->companies[] = $company->uuid;
    }

    protected function driverForCompany($user, string $companyUuid)
    {
        return $user->drivers[$companyUuid] ?? null;
    }

    protected function isNotAdmin($user): bool
    {
        return !$user->admin;
    }

    protected function assignDriverRole($user): void
    {
        $user->assignSingleRole('Driver');
    }
}

class FleetOpsAssignCustomerRolesCommandFake extends AssignCustomerRoles
{
    public array $messages      = [];
    public array $customerUsers = [];
    public Illuminate\Support\Collection $testCustomers;

    public function __construct()
    {
        parent::__construct();
        $this->testCustomers = collect();
    }

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function error($string, $verbosity = null)
    {
        $this->messages[] = ['error', $string];
    }

    protected function customers()
    {
        return $this->testCustomers;
    }

    protected function customerUser(Contact $customer)
    {
        return $this->customerUsers[spl_object_id($customer)] ?? null;
    }

    protected function createUserForCustomer(Contact $customer)
    {
        $this->customerUsers[spl_object_id($customer)] = $customer->createdUser;

        return $customer->createdUser;
    }

    protected function assignCustomerRole($user): void
    {
        $user->assignSingleRole('Fleet-Ops Customer');
    }
}

class FleetOpsRoleUserCommandFake
{
    public string $email;
    public bool $admin         = false;
    public array $roles        = [];
    public array $companies    = [];
    public array $drivers      = [];
    public ?Throwable $failure = null;

    public function __construct(string $email)
    {
        $this->email = $email;
    }

    public function assignSingleRole(string $role): void
    {
        if ($this->failure) {
            throw $this->failure;
        }

        $this->roles[] = $role;
    }
}

class FleetOpsCustomerRoleCommandFake extends Contact
{
    public mixed $createdUser = null;
}

class FleetOpsTrackOrderChunkFake implements IteratorAggregate
{
    public array $loaded = [];

    public function __construct(private array $orders)
    {
    }

    public function load(array $relations): self
    {
        $this->loaded = $relations;

        return $this;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->orders);
    }
}

class FleetOpsTrackOrderFake extends Order
{
    public int $id;
    public array $distanceCalls = [];

    public function __construct(int $id = 0, private bool $shouldFail = false)
    {
        parent::__construct();
        $this->id = $id;
    }

    public function setDistanceAndTime(array $options = []): Order
    {
        if ($this->shouldFail) {
            throw new RuntimeException('distance provider failed');
        }

        $this->distanceCalls[] = $options;

        return $this;
    }
}

class FleetOpsAuditCustomerUserConflictsProbe extends AuditCustomerUserConflicts
{
    public array $messages = [];
    public array $tables   = [];
    public FleetOpsAuditCustomerQueryFake $query;

    public function __construct(private array $testOptions, array $contacts)
    {
        parent::__construct();
        $this->query = new FleetOpsAuditCustomerQueryFake($contacts);
    }

    public function option($key = null)
    {
        return $key === null ? $this->testOptions : ($this->testOptions[$key] ?? null);
    }

    public function line($string, $style = null, $verbosity = null)
    {
        $this->messages[] = ['line', $string];
    }

    public function info($string, $verbosity = null)
    {
        $this->messages[] = ['info', $string];
    }

    public function table($headers, $rows, $tableStyle = 'default', array $columnStyles = [])
    {
        $this->tables[] = [$headers, $rows];
    }

    protected function customerContactsQuery()
    {
        return $this->query;
    }
}

class FleetOpsAuditCustomerQueryFake
{
    public array $calls = [];

    public function __construct(private array $contacts)
    {
    }

    public function where(string $column, mixed $value): self
    {
        $this->calls[] = ['where', $column, $value];

        return $this;
    }

    public function get(): Illuminate\Support\Collection
    {
        $this->calls[] = ['get'];

        return collect($this->contacts);
    }
}

function fleetOpsAuditContact(array $attributes, mixed $user, ?object $company = null): Contact
{
    $contact = new Contact();
    $contact->setRawAttributes(array_merge([
        'uuid'         => 'contact-uuid',
        'public_id'    => 'contact_public',
        'name'         => 'Jane Customer',
        'email'        => 'jane@example.test',
        'company_uuid' => 'company-uuid',
        'updated_at'   => Carbon::parse('2026-07-26 12:00:00'),
    ], $attributes), true);
    $contact->setRelation('anyUser', $user);
    $contact->setRelation('company', $company ?? (object) ['name' => 'Acme Logistics']);

    return $contact;
}

function fleetOpsSyncTelematicsCommandWithOptions(array $options = []): SyncTelematics
{
    return new class($options) extends SyncTelematics {
        public array $messages = [];

        public function __construct(private array $testOptions)
        {
            parent::__construct();
        }

        public function option($key = null)
        {
            return $key === null ? $this->testOptions : ($this->testOptions[$key] ?? null);
        }

        public function info($string, $verbosity = null)
        {
            $this->messages[] = ['info', $string];
        }

        public function warn($string, $verbosity = null)
        {
            $this->messages[] = ['warn', $string];
        }
    };
}

function fleetOpsReminderSchedule(string $uuid, string $publicId, string $name, string $nextDueDate, array $offsets, ?string $email): MaintenanceSchedule
{
    $schedule = (new ReflectionClass(MaintenanceSchedule::class))->newInstanceWithoutConstructor();
    $schedule->setRawAttributes([
        'uuid'                  => $uuid,
        'public_id'             => $publicId,
        'name'                  => $name,
        'next_due_date'         => Carbon::parse($nextDueDate),
        'reminder_offsets'      => json_encode($offsets),
        'default_assignee_uuid' => $email ? $uuid . '-assignee' : null,
    ], true);

    $schedule->setRelation('defaultAssignee', $email ? (object) ['email' => $email] : null);

    return $schedule;
}

function fleetOpsMaintenanceTriggerSchedule(string $uuid, string $publicId, string $name, string $nextDueDate, int $nextOdometer, int $nextEngineHours, Vehicle $subject): MaintenanceSchedule
{
    $schedule = (new ReflectionClass(MaintenanceSchedule::class))->newInstanceWithoutConstructor();
    $schedule->setRawAttributes([
        'uuid'                  => $uuid,
        'public_id'             => $publicId,
        'company_uuid'          => 'company-uuid',
        'subject_type'          => Vehicle::class,
        'subject_uuid'          => $subject->uuid,
        'name'                  => $name,
        'status'                => 'active',
        'next_due_date'         => Carbon::parse($nextDueDate),
        'next_due_odometer'     => $nextOdometer,
        'next_due_engine_hours' => $nextEngineHours,
        'default_priority'      => null,
        'default_assignee_type' => 'user',
        'default_assignee_uuid' => 'assignee-uuid',
        'instructions'          => 'Inspect brakes and tires.',
    ], true);
    $schedule->setRelation('subject', $subject);

    return $schedule;
}

function fleetOpsSimulationDriver(string $uuid = 'driver-uuid', string $publicId = 'driver_public', string $name = 'Jane Driver'): Driver
{
    $driver            = new FleetOpsDispatchAdhocOrdersDriverFake();
    $driver->uuid      = $uuid;
    $driver->public_id = $publicId;
    $driver->name      = $name;

    return $driver;
}

function fleetOpsSimulationOrder(?Driver $assignedDriver = null): FleetOpsSimulateOrderFake
{
    $order = (new ReflectionClass(FleetOpsSimulateOrderFake::class))->newInstanceWithoutConstructor();
    $order->setRawAttributes([
        'uuid'      => 'order-uuid',
        'public_id' => 'order_public',
    ], true);
    $order->assignedDriver = $assignedDriver;
    $order->setRelation('payload', new class {
        public function getPickupOrFirstWaypoint(): object
        {
            return (object) [
                'address'  => '1 Pickup Street',
                'location' => new Point(1.30, 103.80),
            ];
        }

        public function getDropoffOrLastWaypoint(): object
        {
            return (object) [
                'address'  => '99 Dropoff Avenue',
                'location' => new Point(1.32, 103.82),
            ];
        }
    });

    return $order;
}

test('sync telematics exits cleanly when another process holds the lock', function () {
    $lock = new FleetOpsCommandLockFake(false);
    Cache::swap(new FleetOpsCommandCacheFake($lock));

    $registry = new FleetOpsTelematicProviderRegistryFake(collect());
    $command  = fleetOpsSyncTelematicsCommandWithOptions(['no-lock' => false]);

    expect($command->handle($registry))->toBe(Command::SUCCESS)
        ->and($command->messages)->toContain(['warn', 'Another telematics sync run appears to be in progress.'])
        ->and($lock->released)->toBeFalse();
});

test('sync telematics reports no pollable providers after provider filtering', function () {
    $registry = new FleetOpsTelematicProviderRegistryFake(collect([
        'webhook' => new TelematicProviderDescriptor([
            'key'                => 'webhook',
            'label'              => 'Webhook Provider',
            'supports_webhooks'  => true,
            'supports_discovery' => true,
        ]),
        'manual' => new TelematicProviderDescriptor([
            'key'                => 'manual',
            'label'              => 'Manual Provider',
            'supports_discovery' => false,
        ]),
    ]));

    $command = fleetOpsSyncTelematicsCommandWithOptions([
        'no-lock'                   => true,
        'provider'                  => [],
        'exclude-webhook-providers' => true,
    ]);

    expect($command->handle($registry))->toBe(Command::SUCCESS)
        ->and($command->messages)->toContain(['info', 'No pollable telematics providers found.']);
});

test('sync telematics filters requested pollable providers', function () {
    $registry = new FleetOpsTelematicProviderRegistryFake(collect([
        'afaqy' => new TelematicProviderDescriptor([
            'key'                => 'afaqy',
            'label'              => 'Afaqy',
            'supports_discovery' => true,
        ]),
        'samsara' => new TelematicProviderDescriptor([
            'key'                => 'samsara',
            'label'              => 'Samsara',
            'supports_discovery' => true,
        ]),
        'webhook_only' => new TelematicProviderDescriptor([
            'key'                => 'webhook_only',
            'label'              => 'Webhook Only',
            'supports_webhooks'  => true,
            'supports_discovery' => true,
        ]),
    ]));

    $command = fleetOpsSyncTelematicsCommandWithOptions([
        'provider'                  => ['samsara', 'webhook_only'],
        'exclude-webhook-providers' => true,
    ]);

    $method = new ReflectionMethod($command, 'pollableProviderKeys');
    $method->setAccessible(true);

    expect($method->invoke($command, $registry))->toBe(['samsara']);
});

test('debug order tracker command exits successfully', function () {
    expect((new DebugOrderTracker())->handle())->toBe(Command::SUCCESS);
});

test('process maintenance triggers exposes deterministic command helpers', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 04:05:06'));

    $command  = new FleetOpsProcessMaintenanceTriggersProbe();
    $schedule = (object) [
        'next_due_date'         => Carbon::parse('2026-02-01'),
        'next_due_odometer'     => 12000,
        'next_due_engine_hours' => 300,
    ];

    $vehicle               = new Vehicle();
    $vehicle->odometer     = 12500;
    $vehicle->engine_hours = 450;

    expect($command->callHelper('connectionName', true))->toBe('sandbox')
        ->and($command->callHelper('connectionName', false))->toBe('mysql')
        ->and($command->callHelper('currentReadingsFromSubject', $vehicle))->toBe([12500, 450])
        ->and($command->callHelper('currentReadingsFromSubject', new stdClass()))->toBe([null, null])
        ->and($command->callHelper('triggerReasons', $schedule, 12500, 450))->toBe([
            'date due 2026-02-01',
            'odometer 12500 >= 12000',
            'engine hours 450 >= 300',
        ])
        ->and($command->callHelper('triggerReasons', $schedule, 11000, 250))->toBe([
            'date due 2026-02-01',
        ])
        ->and($command->callHelper('workOrderCode', 7, Carbon::parse('2026-02-03')))->toBe('WO-20260203-0007')
        ->and($command->callHelper('processedSummary', 2, false))->toBe('Processed 2 schedule trigger(s).')
        ->and($command->callHelper('processedSummary', 2, true))->toBe('Processed 2 schedule trigger(s) (dry run — no work orders created)');

    Carbon::setTestNow();
});

test('process maintenance triggers handles dry run create and duplicate branches', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 04:05:06'));

    $vehicle               = new Vehicle();
    $vehicle->uuid         = 'vehicle-uuid';
    $vehicle->odometer     = 12500;
    $vehicle->engine_hours = 450;

    $due = fleetOpsMaintenanceTriggerSchedule(
        'schedule-due',
        'schedule_public',
        'Brake Service',
        '2026-02-01',
        12000,
        300,
        $vehicle
    );
    $notDue = fleetOpsMaintenanceTriggerSchedule(
        'schedule-not-due',
        'schedule_future',
        'Future Service',
        '2026-03-01',
        13000,
        600,
        $vehicle
    );

    $dryRun                = new FleetOpsProcessMaintenanceTriggersHandleFake(['sandbox' => true, 'dry-run' => true]);
    $dryRun->testSchedules = collect([$due, $notDue]);
    $dryRun->handle();

    $create                 = new FleetOpsProcessMaintenanceTriggersHandleFake(['sandbox' => false, 'dry-run' => false]);
    $create->testSchedules  = collect([$due, $notDue]);
    $create->workOrderCount = 6;
    $create->handle();

    $skipOpen                = new FleetOpsProcessMaintenanceTriggersHandleFake(['sandbox' => false, 'dry-run' => false]);
    $skipOpen->testSchedules = collect([$due]);
    $skipOpen->existingOpen  = true;
    $skipOpen->handle();

    expect($dryRun->operations)->toBe([['schedules', 'sandbox']])
        ->and($dryRun->createdWorkOrders)->toBe([])
        ->and($dryRun->dispatchedEvents)->toBe([])
        ->and($dryRun->messages)->toContain(
            ['info', 'Processing maintenance schedule triggers [DRY RUN] at 2026-02-03 04:05:06'],
            ['info', 'Processed 1 schedule trigger(s) (dry run — no work orders created)']
        )
        ->and($dryRun->messages[1][1])->toContain('Triggered: schedule schedule_public (Brake Service)')
        ->and($create->operations)->toBe([
            ['schedules', 'mysql'],
            ['openWorkOrderExists', 'mysql', 'schedule-due'],
            ['workOrderCount', 'mysql'],
            ['createWorkOrder', 'mysql'],
        ])
        ->and($create->createdWorkOrders)->toHaveCount(1)
        ->and($create->createdWorkOrders[0])->toMatchArray([
            'company_uuid'    => 'company-uuid',
            'schedule_uuid'   => 'schedule-due',
            'subject'         => 'Brake Service',
            'category'        => 'preventive_maintenance',
            'code'            => 'WO-20260203-0007',
            'status'          => 'open',
            'priority'        => 'normal',
            'target_type'     => Vehicle::class,
            'target_uuid'     => 'vehicle-uuid',
            'assignee_type'   => 'user',
            'assignee_uuid'   => 'assignee-uuid',
            'instructions'    => 'Inspect brakes and tires.',
            'created_by_uuid' => null,
        ])
        ->and($create->createdWorkOrders[0]['due_at']->toDateString())->toBe('2026-02-01')
        ->and($create->createdWorkOrders[0]['opened_at']->toDateTimeString())->toBe('2026-02-03 04:05:06')
        ->and($create->dispatchedEvents)->toBe([['schedule-due', 'work_order_created']])
        ->and($create->messages)->toContain(
            ['line', '  → Created work order work_order_created'],
            ['info', 'Processed 1 schedule trigger(s).']
        )
        ->and($skipOpen->operations)->toBe([
            ['schedules', 'mysql'],
            ['openWorkOrderExists', 'mysql', 'schedule-due'],
        ])
        ->and($skipOpen->createdWorkOrders)->toBe([])
        ->and($skipOpen->dispatchedEvents)->toBe([])
        ->and($skipOpen->messages)->toContain(
            ['line', '  → Skipped: open work order already exists for this schedule.'],
            ['info', 'Processed 0 schedule trigger(s).']
        );

    Carbon::setTestNow();
});

test('track order distance command exits when another estimation run is locked', function () {
    $lock = new FleetOpsCommandLockFake(false);
    Cache::swap(new FleetOpsCommandCacheFake($lock));

    $command = new FleetOpsTrackOrderDistanceAndTimeProbe([
        'provider' => null,
        'days'     => 2,
        'chunk'    => 250,
        'dry'      => false,
        'no-lock'  => false,
    ]);

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($command->messages)->toContain(['info', 'Using provider: '])
        ->and($command->messages)->toContain(['info', 'Looking back: last 2 day(s)'])
        ->and($command->messages)->toContain(['info', 'Chunk size: 250'])
        ->and($command->messages)->toContain(['warn', 'Another run appears to be in progress (lock active). Use --no-lock to bypass.'])
        ->and($lock->released)->toBeFalse();
});

test('track order distance command processes chunks and records handled order errors', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00'));

    $first  = new FleetOpsTrackOrderFake(101);
    $second = new FleetOpsTrackOrderFake(102, shouldFail: true);
    $third  = new FleetOpsTrackOrderFake(103);

    $command = new FleetOpsTrackOrderDistanceAndTimeProbe([
        'provider' => 'osrm',
        'days'     => 0,
        'chunk'    => 10,
        'dry'      => false,
        'no-lock'  => true,
    ], [$first, $second, $third]);

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($command->messages)->toContain(['info', 'Using provider: osrm'])
        ->and($command->messages)->toContain(['info', 'Looking back: last 1 day(s)'])
        ->and($command->messages)->toContain(['info', 'Chunk size: 50'])
        ->and($command->messages)->toContain(['activeOrdersQuery', '2026-07-25 12:00:00'])
        ->and($command->messages)->toContain(['createProgressBar', 3])
        ->and($command->messages)->toContain(['error', 'Order 102 failed: distance provider failed'])
        ->and($command->messages)->toContain(['newLine', 2])
        ->and($command->messages)->toContain(['info', 'Updated 2/3 orders.'])
        ->and($command->messages)->toContain(['warn', 'Encountered 1 error(s). Check logs for details.'])
        ->and($command->query->calls)->toContain(['orderBy', 'id'])
        ->and($command->query->calls)->toContain(['chunkById', 50])
        ->and($command->progressBar->started)->toBe(1)
        ->and($command->progressBar->advanced)->toBe(3)
        ->and($command->progressBar->finished)->toBe(1)
        ->and($first->distanceCalls)->toBe([['provider' => 'osrm']])
        ->and($second->distanceCalls)->toBe([])
        ->and($third->distanceCalls)->toBe([['provider' => 'osrm']]);

    Carbon::setTestNow();
});

test('audit customer user conflicts outputs suspicious rows as json and applies company filters', function () {
    $adminUser = (object) [
        'uuid'         => 'user-uuid',
        'email'        => 'admin@example.test',
        'type'         => 'admin',
        'companyUsers' => collect([
            (object) [
                'company_uuid' => 'company-uuid',
                'roles'        => collect([
                    (object) ['name' => 'Admin'],
                    (object) ['name' => 'Fleet-Ops Customer'],
                ]),
            ],
        ]),
    ];
    $contact = fleetOpsAuditContact([], $adminUser);

    $command = new FleetOpsAuditCustomerUserConflictsProbe([
        'company' => 'company-uuid',
        'json'    => true,
    ], [$contact]);

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($command->query->calls)->toContain(['where', 'company_uuid', 'company-uuid'])
        ->and($command->messages)->toHaveCount(1)
        ->and(json_decode($command->messages[0][1], true))->toMatchArray([
            [
                'contact_id'    => 'contact_public',
                'contact_uuid'  => 'contact-uuid',
                'contact_name'  => 'Jane Customer',
                'contact_email' => 'jane@example.test',
                'user_uuid'     => 'user-uuid',
                'user_email'    => 'admin@example.test',
                'user_type'     => 'admin',
                'roles'         => 'Admin, Fleet-Ops Customer',
                'company_uuid'  => 'company-uuid',
                'company_name'  => 'Acme Logistics',
                'updated_at'    => '2026-07-26 12:00:00',
                'reason'        => 'linked user type is admin; linked user has non-customer roles: Admin',
            ],
        ]);
});

test('audit customer user conflicts handles missing users tables and clean audits', function () {
    $missingUserContact = fleetOpsAuditContact([
        'uuid'      => 'missing-contact-uuid',
        'public_id' => 'missing_contact',
        'name'      => 'Missing User',
    ], null);
    $customerUser = (object) [
        'uuid'         => 'customer-user-uuid',
        'email'        => 'customer@example.test',
        'type'         => 'customer',
        'companyUsers' => collect([
            (object) [
                'company_uuid' => 'company-uuid',
                'roles'        => collect([
                    (object) ['name' => 'Fleet-Ops Customer'],
                ]),
            ],
        ]),
    ];
    $cleanContact = fleetOpsAuditContact([
        'uuid'      => 'clean-contact-uuid',
        'public_id' => 'clean_contact',
    ], $customerUser);

    $tableCommand = new FleetOpsAuditCustomerUserConflictsProbe([
        'company' => null,
        'json'    => false,
    ], [$missingUserContact, $cleanContact]);
    $cleanCommand = new FleetOpsAuditCustomerUserConflictsProbe([
        'company' => null,
        'json'    => false,
    ], [$cleanContact]);

    expect($tableCommand->handle())->toBe(Command::SUCCESS)
        ->and($tableCommand->tables)->toHaveCount(1)
        ->and($tableCommand->tables[0][1][0])->toMatchArray([
            'contact_id' => 'missing_contact',
            'user_uuid'  => null,
            'user_type'  => null,
            'roles'      => '',
            'reason'     => 'missing linked user',
        ])
        ->and($cleanCommand->handle())->toBe(Command::SUCCESS)
        ->and($cleanCommand->messages)->toContain(['info', 'No suspicious customer user conflicts found.']);
});

test('send maintenance reminders sends eligible reminders and skips ineligible schedules', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 09:30:00'));

    $eligible = fleetOpsReminderSchedule(
        'schedule-eligible',
        'schedule_public',
        'Quarterly inspection',
        '2026-07-28',
        [7, 1],
        'mechanic@example.test',
    );
    $alreadySent = fleetOpsReminderSchedule(
        'schedule-already',
        'schedule_already',
        'Already sent',
        '2026-07-26',
        [0],
        'lead@example.test',
    );
    $withoutEmail = fleetOpsReminderSchedule(
        'schedule-no-email',
        'schedule_no_email',
        'No assignee email',
        '2026-07-27',
        [7],
        null,
    );

    $alreadySentKey = implode('|', ['sandbox', 'schedule-already', 0, '2026-07-26']);
    $command        = new FleetOpsSendMaintenanceRemindersCommandFake([
        'sandbox' => true,
        'dry-run' => false,
    ], [$alreadySentKey]);
    $command->testSchedules = collect([$eligible, $alreadySent, $withoutEmail]);

    $command->handle();

    expect($command->messages)->toContain(['schedules', 'sandbox'])
        ->and($command->sent)->toBe([
            ['mechanic@example.test', 'schedule-eligible', 7],
        ])
        ->and($command->records)->toBe([
            ['sandbox', 'schedule-eligible', 7, '2026-07-28'],
        ])
        ->and($command->messages)->toContain(['info', 'Sent 1 reminder(s).'])
        ->and(collect($command->messages)->pluck(1)->filter()->contains(fn ($message) => str_contains($message, 'no email on default assignee')))->toBeTrue();

    Carbon::setTestNow();
});

test('send maintenance reminders dry run counts reminders without sending or recording', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 09:30:00'));

    $command                = new FleetOpsSendMaintenanceRemindersCommandFake([
        'sandbox' => false,
        'dry-run' => true,
    ]);
    $command->testSchedules = collect([
        fleetOpsReminderSchedule(
            'schedule-dry-run',
            'schedule_dry_run',
            'Dry run service',
            '2026-07-30',
            [7],
            'dry@example.test',
        ),
    ]);

    $command->handle();

    expect($command->messages)->toContain(['schedules', 'mysql'])
        ->and($command->sent)->toBe([])
        ->and($command->records)->toBe([])
        ->and($command->messages)->toContain(['info', 'Sent 1 reminder(s) (dry run — no emails sent)']);

    Carbon::setTestNow();
});

test('simulate order route navigation dispatches waypoint events with headings', function () {
    $driver  = fleetOpsSimulationDriver();
    $command = new FleetOpsSimulateOrderRouteNavigationCommandFake([
        'order'  => 'order_public',
        'driver' => 'driver_public',
    ]);
    $command->order     = fleetOpsSimulationOrder();
    $command->driver    = $driver;
    $command->route     = ['code' => 'Ok', 'routes' => [['geometry' => 'encoded-route']]];
    $command->waypoints = [
        new Point(1.30, 103.80),
        new Point(1.31, 103.81),
        new Point(1.32, 103.82),
    ];

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($command->messages)->toContain(['findOrder', 'order_public'])
        ->and($command->messages)->toContain(['findDriver', 'driver_public'])
        ->and($command->messages)->toContain(['decodePolyline', 'encoded-route'])
        ->and($command->messages)->toContain(['info', 'Order pickup point located at: 1 Pickup Street'])
        ->and($command->messages)->toContain(['info', 'Order dropoff point located at: 99 Dropoff Avenue'])
        ->and($command->events)->toHaveCount(3)
        ->and($command->events[0][0])->toBe('driver_public')
        ->and($command->events[0][2])->toHaveKey('heading')
        ->and($command->events[0][2]['index'])->toBe(0)
        ->and($command->events[1][2])->toHaveKey('heading')
        ->and($command->events[2][2])->toBe(['index' => 2])
        ->and($command->pauses)->toBe(3)
        ->and($command->promptQuestions())->toBe([
            'order' => 'Which order ID should be used to simulate driving the route for?',
        ]);
});

test('simulate order route navigation handles missing order driver fallback and unroutable responses', function () {
    $missingOrder = new FleetOpsSimulateOrderRouteNavigationCommandFake([
        'order'  => 'missing-order',
        'driver' => null,
    ]);

    expect($missingOrder->handle())->toBe(Command::FAILURE)
        ->and($missingOrder->messages)->toContain(['error', 'Order not found to simulate driving for.']);

    $fallbackDriver = fleetOpsSimulationDriver('assigned-driver-uuid', 'assigned_driver', 'Assigned Driver');
    $fallback       = new FleetOpsSimulateOrderRouteNavigationCommandFake([
        'order'  => 'order_public',
        'driver' => 'missing-driver',
    ]);
    $fallback->order  = fleetOpsSimulationOrder($fallbackDriver);
    $fallback->route  = ['code' => 'NoRoute'];
    $fallback->driver = null;

    expect($fallback->handle())->toBe(Command::SUCCESS)
        ->and($fallback->messages)->toContain(['findDriver', 'missing-driver'])
        ->and($fallback->messages)->toContain(['error', 'The driver specified was not found, defaulting to driver assigned to order.'])
        ->and($fallback->messages)->toContain(['info', 'Route navigation simulation completed.'])
        ->and($fallback->events)->toBe([])
        ->and($fallback->pauses)->toBe(0);

    $withoutDriver        = new FleetOpsSimulateOrderRouteNavigationCommandFake([
        'order'  => 'order_public',
        'driver' => null,
    ]);
    $withoutDriver->order = fleetOpsSimulationOrder();

    expect($withoutDriver->handle())->toBe(Command::FAILURE)
        ->and($withoutDriver->messages)->toContain(['error', 'No driver found to simulate the order.']);
});

test('dispatch adhoc command exits when no orders are dispatchable', function () {
    $command = new FleetOpsDispatchAdhocOrdersCommandFake([
        'sandbox' => false,
        'testing' => false,
        'days'    => 0,
    ]);

    $command->handle();

    expect($command->messages)->toContain(['info', 'Running in production mode.'])
        ->and($command->messages)->toContain(['info', 'Looking back 1 day(s) for dispatchable orders...'])
        ->and($command->messages)->toContain(['getDispatchableOrders', 1, 4, 72])
        ->and($command->messages)->toContain(['info', 'No dispatchable orders found in the given timeframe.']);
});

test('dispatch adhoc command handles invalid pickups empty drivers and successful pings', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-06 07:08:09'));

    $invalidOrder                 = new FleetOpsDispatchAdhocOrdersOrderFake();
    $invalidOrder->public_id      = 'order_invalid';
    $invalidOrder->dispatched_at  = '2026-05-06 06:00:00';
    $invalidOrder->pickupLocation = null;

    $emptyDriverOrder                 = new FleetOpsDispatchAdhocOrdersOrderFake();
    $emptyDriverOrder->public_id      = 'order_empty';
    $emptyDriverOrder->dispatched_at  = '2026-05-06 06:05:00';
    $emptyDriverOrder->pickupLocation = new Point(1.3, 103.8);
    $emptyDriverOrder->adhocDistance  = 1200;

    $pingOrder                 = new FleetOpsDispatchAdhocOrdersOrderFake();
    $pingOrder->public_id      = 'order_ping';
    $pingOrder->dispatched_at  = '2026-05-06 06:10:00';
    $pingOrder->pickupLocation = new Point(1.31, 103.81);
    $pingOrder->adhocDistance  = 2400;

    $driver            = new FleetOpsDispatchAdhocOrdersDriverFake();
    $driver->public_id = 'driver_public';
    $driver->name      = 'Jane Driver';

    $command         = new class(['sandbox' => true, 'testing' => true, 'days' => 3], $pingOrder, $driver) extends FleetOpsDispatchAdhocOrdersCommandFake {
        public function __construct(array $options, private Order $pingOrder, private Driver $driver)
        {
            parent::__construct($options);
        }

        public function getNearbyDriversForOrder(Order $order, Point $pickup, int $distance, bool $testing = false): EloquentCollection
        {
            parent::getNearbyDriversForOrder($order, $pickup, $distance, $testing);

            return $order === $this->pingOrder ? new EloquentCollection([$this->driver]) : new EloquentCollection();
        }
    };
    $command->orders = new EloquentCollection([$invalidOrder, $emptyDriverOrder, $pingOrder]);

    $command->handle();

    expect($command->messages)->toContain(['info', 'Running in sandbox mode.'])
        ->and($command->messages)->toContain(['alert', '3 orders found for ad-hoc dispatch. Current Time: 2026-05-06 07:08:09'])
        ->and($command->messages)->toContain(['error', 'Invalid pickup location for order order_invalid'])
        ->and($command->messages)->toContain(['warn', 'No available drivers found for order order_empty'])
        ->and($command->messages)->toContain(['line', 'Checking order order_ping for nearby drivers within 2400 meters.'])
        ->and($command->messages)->toContain(['info', 'Order order_ping dispatched successfully to 1 nearby drivers.'])
        ->and($command->messages)->toContain(['info', 'Pinging driver Jane Driver (driver_public) ...'])
        ->and($pingOrder->dispatchedForPing)->toBeTrue()
        ->and($driver->notifications)->toBe([OrderPing::class])
        ->and($command->tables)->toHaveCount(1);

    Carbon::setTestNow();
});

test('dispatch adhoc command builds dispatchable order and nearby driver queries', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-06 07:08:09'));

    $command = new FleetOpsDispatchAdhocOrdersQueryProbe([
        'sandbox' => true,
    ]);

    expect($command->getDispatchableOrders(3))->toBe($command->orderQuery->results)
        ->and($command->orderQuery->calls[0])->toBe(['connection', 'sandbox'])
        ->and($command->orderQuery->calls)->toContain(['where', [['adhoc' => 1, 'dispatched' => 1, 'started' => 0]]])
        ->and($command->orderQuery->calls)->toContain(['whereNull', 'driver_assigned_uuid'])
        ->and($command->orderQuery->calls)->toContain(['whereNull', 'deleted_at'])
        ->and($command->orderQuery->calls)->toContain(['where', ['status', '!=', 'canceled']])
        ->and($command->orderQuery->calls)->toContain(['whereHas', 'company'])
        ->and($command->orderQuery->calls)->toContain(['whereHas', 'payload'])
        ->and($command->orderQuery->calls)->toContain(['with', ['company', 'payload']]);

    $order = new Order();
    $order->setRawAttributes(['company_uuid' => 'company-uuid']);
    $point = new Point(1.3, 103.8);

    expect($command->getNearbyDriversForOrder($order, $point, 500, true))->toBe($command->driverQuery->results)
        ->and($command->driverQuery->calls)->toContain(['where', [['online' => 1]]])
        ->and($command->driverQuery->calls)->toContain(['whereNull', 'deleted_at'])
        ->and($command->driverQuery->calls)->not->toContain(['whereNotNull', 'location']);

    $command = new FleetOpsDispatchAdhocOrdersQueryProbe([
        'sandbox' => false,
    ]);

    $command->getNearbyDriversForOrder($order, $point, 750, false);

    expect($command->driverQuery->calls)->toContain(['whereNotNull', 'location'])
        ->and($command->driverQuery->calls)->toContain(['distanceSphere', 'location', $point, 750])
        ->and($command->driverQuery->calls)->toContain(['distanceSphereValue', 'location', $point]);

    Carbon::setTestNow();
});

test('legacy order config command creates configs and updates legacy orders', function () {
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-uuid'], true);

    $order = new FleetOpsLegacyOrderFake();
    $order->setRawAttributes(['uuid' => 'order-uuid', 'company_uuid' => 'company-uuid'], true);

    $config = new OrderConfig();
    $config->setRawAttributes(['uuid' => 'config-uuid'], true);

    $command                = new FleetOpsFixLegacyOrderConfigsCommandFake(true);
    $command->testCompanies = collect([$company]);
    $command->testOrders    = collect([$order]);
    $command->configs       = ['company-uuid' => $config];

    $command->handle();

    expect($command->created)->toBe(['company-uuid'])
        ->and($order->updates)->toBe([['order_config_uuid' => 'config-uuid']])
        ->and($command->messages)->toContain(['info', 'Initializing transport config for 1 companies.'])
        ->and($command->messages)->toContain(['info', '1 orders found for updating.'])
        ->and($command->messages)->toContain(['info', 'All orders have been processed.'])
        ->and($command->progressBar->started)->toBe(2)
        ->and($command->progressBar->advanced)->toBe(2)
        ->and($command->progressBar->finished)->toBe(2);
});

test('legacy order config command reports order update errors and continues', function () {
    $order = new FleetOpsLegacyOrderFake();
    $order->setRawAttributes(['uuid' => 'order-error', 'company_uuid' => 'company-uuid'], true);
    $order->failUpdate = true;

    $config = new OrderConfig();
    $config->setRawAttributes(['uuid' => 'config-uuid'], true);

    $command             = new FleetOpsFixLegacyOrderConfigsCommandFake(false);
    $command->testOrders = collect([$order]);
    $command->configs    = ['company-uuid' => $config];

    $command->handle();

    expect($command->created)->toBe([])
        ->and($command->messages)->toContain(['error', 'order update failed'])
        ->and($command->messages)->toContain(['error', 'Order ID: order-error'])
        ->and($command->messages)->toContain(['info', 'All orders have been processed.'])
        ->and($command->progressBar->advanced)->toBe(0)
        ->and($command->progressBar->finished)->toBe(1);
});

test('fix customer companies command creates or links users and assigns missing company records', function () {
    $createdUser = new FleetOpsUserCommandFake('created-user', 'Created User', 'created@example.test');

    $customerWithoutUser = new FleetOpsCustomerCommandFake();
    $customerWithoutUser->setRawAttributes([
        'name'         => 'Created Customer',
        'email'        => 'created@example.test',
        'phone'        => '100',
        'company_uuid' => 'company-created',
    ], true);
    $customerWithoutUser->createdUser = $createdUser;

    $existingUser = new FleetOpsUserCommandFake('existing-user', 'Existing User', 'existing@example.test');

    $customerWithExistingEmail = new FleetOpsCustomerCommandFake();
    $customerWithExistingEmail->setRawAttributes([
        'name'         => 'Existing Customer',
        'email'        => 'existing@example.test',
        'phone'        => '200',
        'company_uuid' => 'company-existing',
    ], true);
    $customerWithExistingEmail->failCreate = true;

    $createdCompany = new Company();
    $createdCompany->setRawAttributes(['uuid' => 'company-created', 'name' => 'Created Company'], true);

    $existingCompany = new Company();
    $existingCompany->setRawAttributes(['uuid' => 'company-existing', 'name' => 'Existing Company'], true);

    $command                = new FleetOpsFixCustomerCompaniesCommandFake();
    $command->testCustomers = collect([$customerWithoutUser, $customerWithExistingEmail]);
    $command->users         = ['existing@example.test' => $existingUser];
    $command->missing       = [
        'created-user:company-created'   => true,
        'existing-user:company-existing' => true,
    ];
    $command->companies     = [
        'company-created'  => $createdCompany,
        'company-existing' => $existingCompany,
    ];

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($command->messages)->toContain(['info', 'User created for customer (Created Customer - created@example.test)'])
        ->and($command->messages)->toContain(['error', 'existing user'])
        ->and($command->messages)->toContain(['error', 'Existing user: existing@example.test'])
        ->and($command->messages)->toContain(['info', 'Update customer user to existing user of the same email address.'])
        ->and($customerWithExistingEmail->quietUpdates)->toBe([['user_uuid' => 'existing-user']])
        ->and($createdUser->synced)->toBe([
            ['email', FleetOpsCustomerCommandFake::class],
            ['phone', FleetOpsCustomerCommandFake::class],
        ])
        ->and($existingUser->synced)->toBe([
            ['email', FleetOpsCustomerCommandFake::class],
            ['phone', FleetOpsCustomerCommandFake::class],
        ])
        ->and($createdUser->assigned)->toBe([['company-created', 'Administrator']])
        ->and($existingUser->assigned)->toBe([['company-existing', 'Administrator']]);
});

test('fix driver companies command syncs assigned users and missing company assignments', function () {
    $user = new FleetOpsUserCommandFake('driver-user', 'Driver User', 'driver@example.test');

    $driver = new Driver();
    $driver->setRawAttributes([
        'email'        => 'driver@example.test',
        'phone'        => '300',
        'company_uuid' => 'driver-company',
    ], true);
    $driver->setRelation('user', $user);

    $company = new Company();
    $company->setRawAttributes(['uuid' => 'driver-company', 'name' => 'Driver Company'], true);

    $command              = new FleetOpsFixDriverCompaniesCommandFake();
    $command->testDrivers = collect([$driver]);
    $command->missing     = ['driver-user:driver-company' => true];
    $command->companies   = ['driver-company' => $company];

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($user->synced)->toBe([
            ['email', Driver::class],
            ['phone', Driver::class],
        ])
        ->and($user->assigned)->toBe([['driver-company', 'Administrator']])
        ->and($command->messages)->toContain(['line', 'Found driver Driver User (driver@example.test) which doesnt have correct company assignment.'])
        ->and($command->messages)->toContain(['line', 'Driver driver@example.test was assigned to company: Driver Company']);
});

test('purge unpurchased service quotes command commits deletes and rolls back failures', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-06 12:00:00'));

    $deleted               = new FleetOpsPurgeServiceQuotesCommandFake();
    $deleted->deletedCount = 3;

    expect($deleted->handle())->toBe(Command::SUCCESS)
        ->and($deleted->events[0])->toBe('disable')
        ->and($deleted->events[1])->toBe('begin')
        ->and($deleted->events[2][0])->toBe('purge')
        ->and($deleted->events[2][1]->toDateTimeString())->toBe('2026-05-04 12:00:00')
        ->and($deleted->events)->toContain('commit')
        ->and($deleted->events)->toContain('enable')
        ->and($deleted->messages)->toContain(['info', 'Successfully deleted 3 unpurchased service quotes.']);

    $empty = new FleetOpsPurgeServiceQuotesCommandFake();

    expect($empty->handle())->toBe(Command::SUCCESS)
        ->and($empty->messages)->toContain(['info', 'No unpurchased service quotes found for deletion.']);

    $failed          = new FleetOpsPurgeServiceQuotesCommandFake();
    $failed->failure = new RuntimeException('delete failed');

    expect($failed->handle())->toBe(Command::FAILURE)
        ->and($failed->events)->toContain('rollback')
        ->and($failed->events)->toContain('enable')
        ->and($failed->messages)->toContain(['error', 'Error deleting unpurchased service quotes: delete failed']);

    Carbon::setTestNow();
});

test('send driver notification command handles lookup validation choices and ping notifications', function () {
    $missing = new FleetOpsSendDriverNotificationCommandFake([
        'id'    => null,
        'event' => 'assigned',
    ]);

    expect($missing->handle())->toBe(1)
        ->and($missing->questions)->toBe([['Enter the order ID to trigger the notification', null]])
        ->and($missing->messages)->toContain(['findOrder', 'asked_order'])
        ->and($missing->messages)->toContain(['error', 'Order not found!']);

    $withoutDriver        = new FleetOpsSendDriverNotificationCommandFake([
        'id'    => 'order_public',
        'event' => 'assigned',
    ]);
    $withoutDriver->order                        = new FleetOpsNotificationOrderCommandFake();
    $withoutDriver->order->driverAssignedForTest = null;

    expect($withoutDriver->handle())->toBe(1)
        ->and($withoutDriver->messages)->toContain(['error', 'Order does not have a driver assigned!']);

    $driver                  = new FleetOpsNotificationDriverCommandFake();
    $driver->locationForTest = 'driver-location';

    $order                        = new FleetOpsNotificationOrderCommandFake();
    $order->driverAssignedForTest = $driver;
    $order->payloadForTest        = new FleetOpsNotificationPayloadCommandFake('pickup-location');

    $ping        = new FleetOpsSendDriverNotificationCommandFake([
        'id'    => 'order_public',
        'event' => 'ping',
    ]);
    $ping->order = $order;

    expect($ping->handle())->toBe(0)
        ->and($ping->messages)->toContain(['distance', 'driver-location', 'pickup-location'])
        ->and($ping->messages)->toContain(['info', "Notification 'ping' has been triggered for order ID 'order_public'."])
        ->and($ping->sent)->toBe([[$driver, OrderPing::class, 'order_public', 1234]]);

    $choice        = new FleetOpsSendDriverNotificationCommandFake([
        'id'    => 'order_public',
        'event' => null,
    ]);
    $choice->order = $order;

    expect($choice->handle())->toBe(0)
        ->and($choice->choices[0][0])->toBe('Select the event to trigger')
        ->and($choice->choices[0][1])->toBe(['assigned', 'canceled', 'dispatched', 'ping'])
        ->and($choice->sent)->toBe([[$driver, OrderAssigned::class, 'order_public', null]]);

    $invalid        = new FleetOpsSendDriverNotificationCommandFake([
        'id'    => 'order_public',
        'event' => 'unknown',
    ]);
    $invalid->order = $order;

    expect($invalid->handle())->toBe(1)
        ->and($invalid->messages)->toContain(['error', 'Invalid event selected!']);
});

test('dispatch orders command dispatches ready scheduled orders and warns for unready ones', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-06 07:08:09'));

    $ready                     = new FleetOpsScheduledOrderCommandFake();
    $ready->publicIdForTest    = 'order_ready';
    $ready->scheduledAtForTest = '2026-05-06 07:00:00';
    $ready->ready              = true;

    $waiting                     = new FleetOpsScheduledOrderCommandFake();
    $waiting->publicIdForTest    = 'order_waiting';
    $waiting->scheduledAtForTest = '2026-05-06 08:00:00';

    $command         = new FleetOpsDispatchOrdersCommandFake(['sandbox' => 'true']);
    $command->orders = new EloquentCollection([$ready, $waiting]);

    $command->handle();

    expect($command->messages)->toContain(['info', 'Running in sandbox mode.'])
        ->and($command->messages)->toContain(['getScheduledOrders', true])
        ->and($command->messages)->toContain(['alert', 'Found 2 orders scheduled for dispatch. Current Time: 2026-05-06 07:08:09'])
        ->and($command->messages)->toContain(['info', 'Order order_ready dispatched successfully (2026-05-06 07:00:00).'])
        ->and($command->messages)->toContain(['warn', 'Order order_waiting is not ready for dispatch (2026-05-06 08:00:00).'])
        ->and($ready->dispatchedForTest)->toBeTrue()
        ->and($waiting->dispatchedForTest)->toBeFalse();

    Carbon::setTestNow();
});

test('assign driver roles command assigns non admin driver users and reports failures', function () {
    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-uuid', 'name' => 'Acme Logistics'], true);

    $driverUser          = new FleetOpsRoleUserCommandFake('driver@example.test');
    $driverUser->drivers = ['company-uuid' => (object) ['uuid' => 'driver-uuid']];

    $adminUser          = new FleetOpsRoleUserCommandFake('admin@example.test');
    $adminUser->admin   = true;
    $adminUser->drivers = ['company-uuid' => (object) ['uuid' => 'admin-driver']];

    $failingUser          = new FleetOpsRoleUserCommandFake('fail@example.test');
    $failingUser->drivers = ['company-uuid' => (object) ['uuid' => 'fail-driver']];
    $failingUser->failure = new RuntimeException('role store failed');

    $company->setRelation('users', collect([$driverUser, $adminUser, $failingUser, (object) ['email' => 'ignored@example.test']]));

    $command                = new FleetOpsAssignDriverRolesCommandFake();
    $command->testCompanies = collect([$company]);

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($driverUser->roles)->toBe(['Driver'])
        ->and($adminUser->roles)->toBe([])
        ->and($failingUser->roles)->toBe([])
        ->and($driverUser->companies)->toBe(['company-uuid'])
        ->and($command->messages)->toContain(['info', 'Acme Logistics - Driver: driver@example.test has been made Driver.'])
        ->and($command->messages)->toContain(['error', 'role store failed']);
});

test('assign customer roles command creates missing users and reports assignment errors', function () {
    $createdUser          = new FleetOpsRoleUserCommandFake('created@example.test');
    $existingUser         = new FleetOpsRoleUserCommandFake('existing@example.test');
    $failingUser          = new FleetOpsRoleUserCommandFake('fail@example.test');
    $failingUser->failure = new RuntimeException('customer role failed');

    $needsUser = new FleetOpsCustomerRoleCommandFake();
    $needsUser->setRawAttributes(['name' => 'Created Customer', 'email' => 'created@example.test'], true);
    $needsUser->createdUser = $createdUser;

    $existing = new FleetOpsCustomerRoleCommandFake();
    $existing->setRawAttributes(['name' => 'Existing Customer', 'email' => 'existing@example.test'], true);

    $failing = new FleetOpsCustomerRoleCommandFake();
    $failing->setRawAttributes(['name' => 'Fail Customer', 'email' => 'fail@example.test'], true);

    $command                = new FleetOpsAssignCustomerRolesCommandFake();
    $command->testCustomers = collect([$needsUser, $existing, $failing]);
    $command->customerUsers = [
        spl_object_id($existing) => $existingUser,
        spl_object_id($failing)  => $failingUser,
    ];

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($createdUser->roles)->toBe(['Fleet-Ops Customer'])
        ->and($existingUser->roles)->toBe(['Fleet-Ops Customer'])
        ->and($failingUser->roles)->toBe([])
        ->and($command->messages)->toContain(['info', 'Created Customer - Customer: created@example.test has been assigned the Customer role.'])
        ->and($command->messages)->toContain(['info', 'Existing Customer - Customer: existing@example.test has been assigned the Customer role.'])
        ->and($command->messages)->toContain(['error', 'customer role failed']);
});

test('test email command rejects unsupported email types before sending mail', function () {
    $command = new class extends TestEmail {
        public array $messages = [];

        public function argument($key = null)
        {
            $arguments = ['email' => 'customer@example.test'];

            return $key === null ? $arguments : ($arguments[$key] ?? null);
        }

        public function option($key = null)
        {
            $options = ['type' => 'unknown'];

            return $key === null ? $options : ($options[$key] ?? null);
        }

        public function info($string, $verbosity = null)
        {
            $this->messages[] = ['info', $string];
        }

        public function error($string, $verbosity = null)
        {
            $this->messages[] = ['error', $string];
        }
    };

    expect($command->handle())->toBe(Command::FAILURE)
        ->and($command->messages)->toContain(['error', 'Unknown email type: unknown']);
});

test('track order distance command exits early when no qualifying orders exist', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00'));

    $command = new FleetOpsTrackOrderDistanceAndTimeProbe([
        'provider' => null,
        'days'     => 2,
        'chunk'    => 100,
        'dry'      => false,
        'no-lock'  => true,
    ], []);

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($command->messages)->toContain(['info', 'No qualifying orders found. Exiting.']);

    Carbon::setTestNow();
});

test('track order distance real query and progress bar builders execute', function () {
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Model::setConnectionResolver($resolver);
    $schema = $connection->getSchemaBuilder();
    foreach (['orders' => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'status', 'started_at', '_key'], 'payloads' => ['uuid', 'public_id', 'company_uuid', '_key']] as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }
    $connection->table('payloads')->insert(['uuid' => 'payload-track-1', 'company_uuid' => 'company-1']);
    $connection->table('orders')->insert([
        ['uuid' => 'order-track-active', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-track-1', 'status' => 'started', 'started_at' => '2026-07-25 10:00:00', 'created_at' => '2026-07-25 10:00:00', 'updated_at' => '2026-07-25 10:00:00'],
        ['uuid' => 'order-track-done', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-track-1', 'status' => 'completed', 'started_at' => '2026-07-25 10:00:00', 'created_at' => '2026-07-25 10:00:00', 'updated_at' => '2026-07-25 10:00:00'],
    ]);

    $command    = new TrackOrderDistanceAndTime();
    $reflection = new ReflectionMethod(TrackOrderDistanceAndTime::class, 'activeOrdersQuery');
    $reflection->setAccessible(true);
    $query = $reflection->invoke($command, Illuminate\Support\Carbon::parse('2026-07-20 00:00:00'));
    expect($query->count('id'))->toBe(1);

    $output = new class {
        public array $bars = [];

        public function createProgressBar(int $total)
        {
            $this->bars[] = $total;

            return new FleetOpsTrackProgressBarFake();
        }
    };
    $outputProperty = new ReflectionProperty(Command::class, 'output');
    $outputProperty->setAccessible(true);
    $outputProperty->setValue($command, $output);

    $barMethod = new ReflectionMethod(TrackOrderDistanceAndTime::class, 'createProgressBar');
    $barMethod->setAccessible(true);
    expect($barMethod->invoke($command, 7))->toBeInstanceOf(FleetOpsTrackProgressBarFake::class)
        ->and($output->bars)->toBe([7]);
});

class FleetOpsRecordedDriverNotification
{
    public static array $constructed = [];

    public function __construct(...$args)
    {
        static::$constructed[] = $args;
    }
}

class FleetOpsThrowingDriverNotificationCommandFake extends FleetOpsSendDriverNotificationCommandFake
{
    protected function notifyDriver($driver, string $notificationClass, Order $order, mixed $distance = null): void
    {
        throw new Exception('notification channel offline');
    }
}

test('send driver notification real helpers resolve orders distances and notify branches', function () {
    // Real order lookup against sqlite
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Model::setConnectionResolver($resolver);
    $schema = $connection->getSchemaBuilder();
    $schema->create('orders', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'status', 'meta', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });
    $connection->table('orders')->insert(['uuid' => 'order-sdn-1', 'public_id' => 'order_sdnreal1', 'company_uuid' => 'company-1', 'status' => 'created']);

    $command = new SendDriverNotification();
    $helper  = function (string $method, ...$arguments) use ($command) {
        $reflection = new ReflectionMethod(SendDriverNotification::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($command, ...$arguments);
    };

    expect($helper('findOrder', 'order_sdnreal1')?->uuid)->toBe('order-sdn-1')
        ->and($helper('findOrder', 'order_missing0'))->toBeNull();

    // Real driving matrix from two points
    $matrix = $helper('calculateDrivingDistanceAndTime', new Point(1.30, 103.80), new Point(1.31, 103.81));
    expect($matrix->distance)->toBeGreaterThan(0);

    // Both notify branches construct the notification with and without distance
    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-sdn-1', 'public_id' => 'order_sdnreal1'], true);
    $driver = new class {
        public array $notified = [];

        public function notify($notification)
        {
            $this->notified[] = $notification;
        }
    };
    FleetOpsRecordedDriverNotification::$constructed = [];
    $helper('notifyDriver', $driver, FleetOpsRecordedDriverNotification::class, $order, 4321);
    $helper('notifyDriver', $driver, FleetOpsRecordedDriverNotification::class, $order);
    expect($driver->notified)->toHaveCount(2)
        ->and(FleetOpsRecordedDriverNotification::$constructed[0])->toHaveCount(2)
        ->and(FleetOpsRecordedDriverNotification::$constructed[1])->toHaveCount(1);

    // Notification failures surface as command errors
    $driverFake                       = new FleetOpsNotificationDriverCommandFake();
    $orderFake                        = new FleetOpsNotificationOrderCommandFake();
    $orderFake->driverAssignedForTest = $driverFake;

    $failing        = new FleetOpsThrowingDriverNotificationCommandFake([
        'id'    => 'order_public',
        'event' => 'assigned',
    ]);
    $failing->order = $orderFake;

    expect($failing->handle())->toBe(0)
        ->and($failing->messages)->toContain(['error', 'notification channel offline']);
});
