<?php

use Carbon\Carbon;
use Fleetbase\FleetOps\Console\Commands\AuditCustomerUserConflicts;
use Fleetbase\FleetOps\Console\Commands\DispatchAdhocOrders;
use Fleetbase\FleetOps\Console\Commands\ProcessMaintenanceTriggers;
use Fleetbase\FleetOps\Console\Commands\SendMaintenanceReminders;
use Fleetbase\FleetOps\Console\Commands\SyncTelematics;
use Fleetbase\FleetOps\Console\Commands\TestEmail;
use Fleetbase\FleetOps\Console\Commands\TrackOrderDistanceAndTime;
use Fleetbase\FleetOps\Contracts\TelematicProviderDescriptor;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;

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
        ->and($driver->notifications)->toBe([Fleetbase\FleetOps\Notifications\OrderPing::class])
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
