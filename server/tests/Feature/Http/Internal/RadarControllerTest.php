<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\RadarController;
use Fleetbase\FleetOps\Support\Radar\RadarItemState;
use Fleetbase\Models\Alert;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * An alert row that never reaches a database: RadarItemState writes its
 * columns onto it as usual, and saving or deleting is recorded instead of
 * run, so a test reads back exactly what the controller wrote.
 */
class FleetOpsRadarAlertSpy extends Alert
{
    public array $calls = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct();
        foreach (['context', 'meta'] as $json) {
            if (isset($attributes[$json]) && is_array($attributes[$json])) {
                $attributes[$json] = json_encode($attributes[$json]);
            }
        }
        $this->setRawAttributes($attributes, true);
        $this->exists = false;
    }

    // Date casts ask the connection for its format; there is no connection.
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    public function save(array $options = []): bool
    {
        $this->calls[] = ['save'];
        $this->syncOriginal();

        return true;
    }

    public function delete(): ?bool
    {
        $this->calls[] = ['delete'];

        return true;
    }
}

/**
 * The controller with its sources and its state store replaced: the rows
 * come from fixtures, the rows' state lives in an array of spies.
 */
class FleetOpsRadarControllerProbe extends RadarController
{
    /** @var array<string, FleetOpsRadarAlertSpy> */
    public array $store       = [];
    public array $reconciled  = [];
    public array $resolved    = [];
    public array $schedule    = [];
    public ?Alert $lastNotice = null;

    public function __construct(public array $fixtures = [], public array $seededStates = [], public array $failing = [])
    {
    }

    protected function sourceLoaders(): array
    {
        $loaders = [];
        foreach (['schedules', 'workOrders', 'issues', 'drivers', 'shifts', 'vehicles', 'trailers', 'devices', 'parts', 'fuelTransactions', 'inspectionSubmissions', 'inspectionLinks', 'notices'] as $name) {
            $loaders[$name] = function () use ($name) {
                if (in_array($name, $this->failing, true)) {
                    throw new RuntimeException($name . ' table is unavailable');
                }

                return $this->fixtures[$name] ?? [];
            };
        }

        return $loaders;
    }

    protected function statesFor(?string $company): array
    {
        $states = [];
        foreach ($this->store as $key => $spy) {
            $states[$key] = RadarItemState::toState($spy, $this->usersFor([$spy]));
        }

        return $states + $this->seededStates;
    }

    /** The company's users, as the uuid lookup would find them. */
    protected function usersFor(array $rows): array
    {
        return [
            'user-1' => ['uuid' => 'user-1', 'public_id' => 'user_1', 'name' => 'Ada Ops'],
            'user-2' => ['uuid' => 'user-2', 'public_id' => 'user_2', 'name' => 'Bo Tran'],
        ];
    }

    protected function rowFor(?string $company, array $item): ?Alert
    {
        return $this->store[$item['key']] ??= new FleetOpsRadarAlertSpy([
            'uuid'      => 'alert-' . (count($this->store) + 1),
            'public_id' => 'alert_' . (count($this->store) + 1),
            'type'      => $item['rule'],
            'status'    => 'open',
            'context'   => ['key' => $item['key']],
        ]);
    }

    protected function findRow(?string $company, string $key): ?Alert
    {
        return $this->store[$key] ?? null;
    }

    public bool $failReconcile = false;

    protected function reconcile(?string $company, array $liveKeys): int
    {
        if ($this->failReconcile) {
            throw new RuntimeException('alerts are read-only');
        }

        $this->reconciled = $liveKeys;

        return 0;
    }

    protected function resolvedSince(?string $company, Carbon $since, Carbon $now): array
    {
        return $this->resolved;
    }

    protected function snoozeSchedule(?string $company, Carbon $now): array
    {
        return $this->schedule;
    }

    protected function notices(?string $company): array
    {
        return $this->fixtures['notices'] ?? [];
    }

    public array $history   = [];
    public array $orders    = [];
    public ?object $shift   = null;
    public bool $shiftSaved = false;

    protected function loadOrdersForHandovers(?string $company, array $items): array
    {
        return $this->orders;
    }

    protected function findShift(?string $company, string $id): ?Fleetbase\Models\ScheduleItem
    {
        return $id === 'shift_1' ? $this->shift : null;
    }

    protected function saveShift(Fleetbase\Models\ScheduleItem $shift): void
    {
        $this->shiftSaved = true;
    }

    protected function scoreHistory(?string $company): array
    {
        return $this->history;
    }

    protected function rememberScore(?string $company, array $history): void
    {
        $this->history = $history;
    }

    protected function createNotice(array $attributes): Alert
    {
        $this->lastNotice            = new FleetOpsRadarAlertSpy(['uuid' => 'alert-notice', 'public_id' => 'alert_notice'] + $attributes);
        $this->fixtures['notices'][] = [
            'uuid'      => 'alert-notice',
            'public_id' => 'alert_notice',
            'message'   => $attributes['message'],
            'severity'  => $attributes['severity'],
            'status'    => 'open',
            'meta'      => $attributes['meta'],
        ];

        return $this->lastNotice;
    }

    protected function findNotice(?string $company, string $id): ?Alert
    {
        return $id === 'alert_notice' ? ($this->lastNotice ?? new FleetOpsRadarAlertSpy(['uuid' => 'alert-notice', 'public_id' => 'alert_notice', 'type' => 'radar_notice'])) : null;
    }

    protected function now(): Carbon
    {
        return Carbon::parse('2026-09-15 08:35:00', 'UTC');
    }

    protected function companyUuid(Request $request): ?string
    {
        return 'company-radar';
    }

    protected function actor(Request $request): ?User
    {
        return fleetOpsRadarUser('user-1', 'Ada Ops');
    }

    protected function findCompanyUser(?string $company, string $id): ?User
    {
        return $id === 'user_bo' ? fleetOpsRadarUser('user-2', 'Bo Tran') : null;
    }
}

function fleetOpsRadarUser(string $uuid, string $name): User
{
    $user = new User();
    $user->setRawAttributes(['uuid' => $uuid, 'public_id' => str_replace('-', '_', $uuid), 'name' => $name], true);

    return $user;
}

function fleetOpsRadarFixtures(): array
{
    $vehicle = fn (string $id) => ['type' => 'vehicle', 'class' => 'Fleetbase\FleetOps\Models\Vehicle', 'uuid' => 'vehicle-' . $id, 'public_id' => 'vehicle_' . $id, 'label' => strtoupper($id), 'photo_url' => null];

    return [
        'schedules' => [
            ['uuid' => 's1', 'public_id' => 'schedule_oil', 'name' => 'Oil change', 'type' => 'oil_change', 'status' => 'active', 'next_due_date' => '2026-09-12 08:00:00', 'subject' => $vehicle('trk118')],
            ['uuid' => 's2', 'public_id' => 'schedule_tires', 'name' => 'Tire rotation', 'type' => 'tire_rotation', 'status' => 'active', 'next_due_date' => '2026-09-17 12:00:00', 'subject' => $vehicle('trk204')],
        ],
        'issues' => [
            ['uuid' => 'i1', 'public_id' => 'issue_1', 'title' => 'Check engine light', 'priority' => 'high', 'status' => 'pending', 'vehicle' => $vehicle('trk311'), 'driver' => null, 'meta' => []],
        ],
        'parts' => [
            ['uuid' => 'p1', 'public_id' => 'part_pads', 'name' => 'Brake pads', 'quantity_on_hand' => 2, 'reorder_point' => 6],
        ],
        'notices' => [
            ['uuid' => 'n1', 'public_id' => 'alert_yard', 'message' => 'Yard closed Saturday', 'severity' => 'warning', 'status' => 'open', 'meta' => ['due_at' => '2026-09-18 18:00:00'], 'state' => ['status' => 'open']],
        ],
    ];
}

function fleetOpsRadarRequest(string $uri, string $method = 'GET', array $parameters = []): Request
{
    return Request::create('/int/v1/fleet-ops/radar/' . ltrim($uri, '/'), $method, $parameters);
}

afterEach(fn () => Carbon::setTestNow());

test('items lists the open tab grouped by due, with pill counts and the summary', function () {
    $controller = new FleetOpsRadarControllerProbe(fleetOpsRadarFixtures());
    $payload    = $controller->items(fleetOpsRadarRequest('items'))->getData(true);

    expect(array_column($payload['items'], 'key'))->toBe([
        'maintenance_overdue:schedule_oil',
        'maintenance_due_soon:schedule_tires',
        'notice:alert_yard',
        'issue_open:issue_1',
        'part_low_stock:part_pads',
    ])
        ->and(array_column($payload['groups'], 'key'))->toBe(['overdue', 'week', 'none'])
        ->and($payload['meta'])->toBe(['total' => 5, 'page' => 1, 'limit' => 50, 'pages' => 1])
        ->and($payload['counts']['overdue'])->toBe(1)
        ->and($payload['counts']['due_week'])->toBe(2)
        ->and($payload['counts']['notices'])->toBe(1)
        ->and($payload['summary']['open'])->toBe(5)
        ->and($payload['summary']['critical'])->toBe(2)
        ->and($payload['summary']['resolved'])->toBeNull()
        ->and($payload['sources'])->toBe([])
        ->and($payload['generated_at'])->toBe('2026-09-15T08:35:00+00:00')
        ->and($controller->reconciled)->toHaveCount(5)
        ->and($payload['items'][0]['state'])->toMatchArray(['status' => 'open', 'alert_id' => null]);
});

test('items narrows by pills, query, fleet and page, and serves the snoozed and resolved tabs', function () {
    $controller = new FleetOpsRadarControllerProbe(fleetOpsRadarFixtures(), [
        'maintenance_due_soon:schedule_tires' => ['status' => 'snoozed', 'snoozed_until' => '2026-09-18T08:00:00+00:00'],
    ]);
    $controller->resolved = [['key' => 'issue_open:issue_9', 'rule' => 'issue_open', 'severity' => 'warning', 'title' => 'Old issue', 'due_bucket' => 'none', 'pills' => [], 'subject' => null, 'state' => ['status' => 'resolved']]];
    $controller->schedule = [['key' => 'maintenance_due_soon:schedule_tires', 'title' => 'Tire rotation', 'snoozed_until' => '2026-09-18T08:00:00+00:00']];

    $open = $controller->items(fleetOpsRadarRequest('items'))->getData(true);
    expect(array_column($open['items'], 'key'))->not->toContain('maintenance_due_soon:schedule_tires')
        ->and($open['summary']['snoozed'])->toBe(1)
        ->and($open['snooze_schedule'][0]['key'])->toBe('maintenance_due_soon:schedule_tires');

    $pills = $controller->items(fleetOpsRadarRequest('items', 'GET', ['filters' => 'issues,due_week']))->getData(true);
    expect($pills['items'])->toBe([])
        ->and($pills['counts']['issues'])->toBe(1);

    $overdue = $controller->items(fleetOpsRadarRequest('items', 'GET', ['filters' => 'overdue']))->getData(true);
    expect(array_column($overdue['items'], 'key'))->toBe(['maintenance_overdue:schedule_oil']);

    $query = $controller->items(fleetOpsRadarRequest('items', 'GET', ['query' => 'brake']))->getData(true);
    expect(array_column($query['items'], 'key'))->toBe(['part_low_stock:part_pads']);

    $paged = $controller->items(fleetOpsRadarRequest('items', 'GET', ['page' => 2, 'limit' => 3]))->getData(true);
    expect($paged['items'])->toHaveCount(1)
        ->and($paged['meta'])->toBe(['total' => 4, 'page' => 2, 'limit' => 3, 'pages' => 2]);

    $snoozed = $controller->items(fleetOpsRadarRequest('items', 'GET', ['status' => 'snoozed']))->getData(true);
    expect(array_column($snoozed['items'], 'key'))->toBe(['maintenance_due_soon:schedule_tires'])
        ->and($snoozed['items'][0]['state']['snoozed_until'])->toBe('2026-09-18T08:00:00+00:00');

    $resolved = $controller->items(fleetOpsRadarRequest('items', 'GET', ['status' => 'resolved']))->getData(true);
    expect(array_column($resolved['items'], 'key'))->toBe(['issue_open:issue_9'])
        ->and($resolved['summary']['resolved'])->toBe(1);

    $fleet = $controller->items(fleetOpsRadarRequest('items', 'GET', ['fleet' => 'fleet-north']))->getData(true);
    expect($fleet['items'])->toBe([]);

    // "My assignments" keeps only the items whose owner is the caller.
    $controller->store['issue_open:issue_1'] = new FleetOpsRadarAlertSpy(['uuid' => 'a', 'public_id' => 'alert_a', 'type' => 'issue_open', 'status' => 'open', 'assigned_to_uuid' => 'user-1', 'context' => ['key' => 'issue_open:issue_1']]);
    $mine                                    = $controller->items(fleetOpsRadarRequest('items', 'GET', ['assigned' => 'me']))->getData(true);
    expect(array_column($mine['items'], 'key'))->toBe(['issue_open:issue_1']);
});

test('a failing source is reported without blanking the list, and reconciliation is skipped', function () {
    $controller = new FleetOpsRadarControllerProbe(fleetOpsRadarFixtures(), [], ['issues']);
    $payload    = $controller->items(fleetOpsRadarRequest('items'))->getData(true);

    expect(array_column($payload['items'], 'key'))->not->toContain('issue_open:issue_1')
        ->and($payload['items'])->toHaveCount(4)
        ->and($payload['sources'])->toBe(['issues' => 'issues table is unavailable'])
        ->and($controller->reconciled)->toBe([]);
});

test('briefing scores the morning, remembers the score and counts what closed since yesterday', function () {
    $controller = new FleetOpsRadarControllerProbe(fleetOpsRadarFixtures(), [
        'issue_open:issue_1' => ['status' => 'open', 'triggered_at' => '2026-09-13T08:00:00+00:00'],
    ]);
    $controller->history  = [['date' => '2026-09-14', 'score' => 90]];
    $controller->resolved = [['key' => 'issue_open:issue_9', 'rule' => 'issue_open', 'severity' => 'warning', 'title' => 'Old issue', 'due_bucket' => 'none', 'pills' => [], 'subject' => null, 'state' => ['status' => 'resolved']]];

    $payload = $controller->briefing(fleetOpsRadarRequest('briefing'))->getData(true);

    expect($payload)->toHaveKeys(['score', 'categories', 'brief', 'decisions', 'open', 'yesterday', 'generated_at', 'sources'])
        ->and($payload['score']['value'])->toBeLessThan(100)
        ->and($payload['score']['delta'])->toBe($payload['score']['value'] - 90)
        ->and($payload['categories'][0]['key'])->toBe('maintenance')
        ->and($payload['yesterday'])->toBe(['closed' => 1, 'rolled_over' => 1])
        ->and($controller->history)->toHaveCount(2)
        ->and($controller->history[1])->toBe(['date' => '2026-09-15', 'score' => $payload['score']['value']])
        ->and(array_column($payload['decisions'], 'key'))->toBe(['open_work_order:schedule_oil'])
        ->and($controller->reconciled)->toBe([]);

    $byCategory = $controller->items(fleetOpsRadarRequest('items', 'GET', ['category' => 'parts']))->getData(true);
    expect(array_column($byCategory['items'], 'key'))->toBe(['part_low_stock:part_pads']);
});

test('agenda lays the items out on the window, with the roster and handover cards', function () {
    $fixtures           = fleetOpsRadarFixtures();
    $ortega             = ['type' => 'driver', 'class' => 'Fleetbase\FleetOps\Models\Driver', 'uuid' => 'driver-ortega', 'public_id' => 'driver_ortega', 'label' => 'Luis Ortega', 'photo_url' => null, 'phone' => null];
    $alves              = ['type' => 'driver', 'class' => 'Fleetbase\FleetOps\Models\Driver', 'uuid' => 'driver-alves', 'public_id' => 'driver_alves', 'label' => 'Tomas Alves', 'photo_url' => null, 'phone' => null];
    $fixtures['shifts'] = [
        ['uuid' => 'sh1', 'public_id' => 'shift_1', 'start_at' => '2026-09-15 01:15:00', 'end_at' => '2026-09-15 09:15:00', 'status' => 'in_progress', 'driver' => $ortega, 'driver_online' => true, 'driver_vehicle_uuid' => 'v', 'active_orders' => 2],
        ['uuid' => 'sh2', 'public_id' => 'shift_2', 'start_at' => '2026-09-15 06:00:00', 'end_at' => '2026-09-15 21:00:00', 'status' => 'in_progress', 'driver' => $alves, 'driver_online' => true, 'driver_vehicle_uuid' => 'v2', 'active_orders' => 1],
    ];
    $fixtures['drivers'] = [
        ['uuid' => 'driver-ortega', 'public_id' => 'driver_ortega', 'name' => 'Luis Ortega', 'online' => true, 'vehicle_uuid' => 'v', 'active_orders' => 2, 'location' => ['lat' => 40.7, 'lng' => -74.0]],
        ['uuid' => 'driver-alves', 'public_id' => 'driver_alves', 'name' => 'Tomas Alves', 'online' => true, 'vehicle_uuid' => 'v2', 'active_orders' => 1, 'location' => ['lat' => 40.71, 'lng' => -74.0]],
    ];
    $controller         = new FleetOpsRadarControllerProbe($fixtures);
    $controller->orders = ['driver-ortega' => [['uuid' => 'o1', 'public_id' => 'order_1', 'status' => 'dispatched', 'destination' => 'Bay Ridge', 'ends_at' => '2026-09-15T09:40:00+00:00']]];

    $payload = $controller->agenda(fleetOpsRadarRequest('agenda', 'GET', ['window' => '24h']))->getData(true);

    expect($payload['window']['key'])->toBe('24h')
        ->and(array_column($payload['overdue'], 'key'))->toBe(['maintenance_overdue:schedule_oil'])
        ->and(array_column($payload['lanes']['maintenance'], 'key'))->toBe([])
        ->and(array_column($payload['later'], 'key'))->toBe(['maintenance_due_soon:schedule_tires', 'notice:alert_yard'])
        ->and(array_column($payload['anytime'], 'key'))->toBe(['issue_open:issue_1', 'part_low_stock:part_pads'])
        ->and(collect($payload['lanes']['shifts'])->where('kind', 'shift')->count())->toBe(2)
        ->and($payload['handovers'][0]['key'])->toBe('shift_handover:driver_ortega')
        ->and($payload['handovers'][0]['orders'][0]['destination'])->toBe('Bay Ridge')
        ->and($payload['handovers'][0]['suggested']['driver']['label'])->toBe('Tomas Alves')
        ->and($payload['summary']['open'])->toBe(6);

    $card = $controller->handoverSuggest(fleetOpsRadarRequest('handovers/shift_handover:driver_ortega'), 'shift_handover:driver_ortega')->getData(true);
    expect($card['handover']['suggested']['capacity_label'])->toBe('1 of 6 orders');
    expect($controller->handoverSuggest(fleetOpsRadarRequest('handovers/x'), 'shift_handover:driver_nobody')->getStatusCode())->toBe(404);
});

test('extend shift pushes the end out and validates the minutes', function () {
    $controller = new FleetOpsRadarControllerProbe(fleetOpsRadarFixtures());
    $shift      = new class extends Fleetbase\Models\ScheduleItem {
        public function getDateFormat(): string
        {
            return 'Y-m-d H:i:s';
        }
    };
    $shift->setRawAttributes(['uuid' => 'sh1', 'public_id' => 'shift_1', 'start_at' => '2026-09-15 01:15:00', 'end_at' => '2026-09-15 09:15:00', 'status' => 'in_progress'], true);
    $controller->shift = $shift;

    expect($controller->extendShift(fleetOpsRadarRequest('shifts/shift_1/extend', 'POST', ['minutes' => 0]), 'shift_1')->getStatusCode())->toBe(422)
        ->and($controller->extendShift(fleetOpsRadarRequest('shifts/shift_9/extend', 'POST', ['minutes' => 60]), 'shift_9')->getStatusCode())->toBe(404);

    $payload = $controller->extendShift(fleetOpsRadarRequest('shifts/shift_1/extend', 'POST', ['minutes' => 60]), 'shift_1')->getData(true);
    expect($payload['shift']['end_at'])->toBe('2026-09-15T10:15:00+00:00')
        ->and($controller->shiftSaved)->toBeTrue();
});

test('a failed reconcile is reported with the sources and the list still answers', function () {
    $controller                = new FleetOpsRadarControllerProbe(fleetOpsRadarFixtures());
    $controller->failReconcile = true;

    $payload = $controller->items(fleetOpsRadarRequest('items'))->getData(true);

    expect($payload['sources'])->toBe(['reconcile' => 'alerts are read-only'])
        ->and($payload['items'])->not->toBe([]);
});

test('wake reaches a row whose item has since closed, and bulk acknowledge marks every key', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-15 08:35:00', 'UTC'));
    $controller = new FleetOpsRadarControllerProbe(fleetOpsRadarFixtures());

    $controller->store['issue_open:issue_gone'] = new FleetOpsRadarAlertSpy(['uuid' => 'g', 'public_id' => 'alert_gone', 'type' => 'issue_open', 'status' => 'open', 'snoozed_until' => '2026-09-15 10:00:00', 'context' => ['key' => 'issue_open:issue_gone']]);
    $woken                                      = $controller->wake(fleetOpsRadarRequest('items/issue_open:issue_gone/wake', 'POST'), 'issue_open:issue_gone')->getData(true);
    expect($woken['item'])->toBeNull()
        ->and($woken['state']['snoozed_until'])->toBeNull();

    $bulk = $controller->bulk(fleetOpsRadarRequest('items/bulk', 'POST', ['keys' => ['issue_open:issue_1', 'part_low_stock:part_pads'], 'action' => 'acknowledge']))->getData(true);
    expect(array_column(array_column($bulk['results'], 'state'), 'status'))->toBe(['acknowledged', 'acknowledged']);
});

test('summary answers with counts only', function () {
    $controller = new FleetOpsRadarControllerProbe(fleetOpsRadarFixtures());
    $payload    = $controller->summary(fleetOpsRadarRequest('summary'))->getData(true);

    expect($payload)->toHaveKeys(['summary', 'counts', 'generated_at'])
        ->and($payload['summary']['open'])->toBe(5)
        ->and($controller->reconciled)->toBe([]);
});

test('acknowledge creates the row from the live item and answers with the new state', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-15 08:35:00', 'UTC'));
    $controller = new FleetOpsRadarControllerProbe(fleetOpsRadarFixtures());

    $response = $controller->acknowledge(fleetOpsRadarRequest('items/issue_open:issue_1/acknowledge', 'POST'), 'issue_open:issue_1');
    $payload  = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($payload['item']['key'])->toBe('issue_open:issue_1')
        ->and($payload['state']['status'])->toBe('acknowledged')
        ->and($payload['state']['acknowledged_by_name'])->toBe('Ada Ops')
        ->and($payload['state']['alert_id'])->toBe('alert_1')
        ->and($controller->store['issue_open:issue_1']->getAttribute('acknowledged_by_uuid'))->toBe('user-1')
        ->and($controller->store['issue_open:issue_1']->calls)->toBe([['save']]);

    // Acknowledging twice keeps the first acknowledgement and writes nothing.
    $controller->acknowledge(fleetOpsRadarRequest('items/issue_open:issue_1/acknowledge', 'POST'), 'issue_open:issue_1');
    expect($controller->store['issue_open:issue_1']->calls)->toBe([['save']]);

    // The row is reused on the next action instead of created again.
    $controller->acknowledge(fleetOpsRadarRequest('items/issue_open:issue_1/acknowledge', 'POST'), 'issue_open:issue_1');
    expect($controller->store)->toHaveCount(1);

    $missing = $controller->acknowledge(fleetOpsRadarRequest('items/issue_open:issue_404/acknowledge', 'POST'), 'issue_open:issue_404');
    expect($missing->getStatusCode())->toBe(404);

    $unknownRule = $controller->acknowledge(fleetOpsRadarRequest('items/nope:issue_1/acknowledge', 'POST'), 'nope:issue_1');
    expect($unknownRule->getStatusCode())->toBe(404);
});

test('snooze takes minutes or an until date, and wake ends it', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-15 08:35:00', 'UTC'));
    $controller = new FleetOpsRadarControllerProbe(fleetOpsRadarFixtures());
    $key        = 'part_low_stock:part_pads';

    expect($controller->snooze(fleetOpsRadarRequest("items/{$key}/snooze", 'POST'), $key)->getStatusCode())->toBe(422)
        ->and($controller->snooze(fleetOpsRadarRequest("items/{$key}/snooze", 'POST', ['minutes' => 0]), $key)->getStatusCode())->toBe(422)
        ->and($controller->snooze(fleetOpsRadarRequest("items/{$key}/snooze", 'POST', ['until' => '2026-09-14 08:00:00']), $key)->getStatusCode())->toBe(422)
        ->and($controller->snooze(fleetOpsRadarRequest("items/{$key}/snooze", 'POST', ['until' => 'garbage']), $key)->getStatusCode())->toBe(422);

    $wakeBefore = $controller->wake(fleetOpsRadarRequest("items/{$key}/wake", 'POST'), $key);
    expect($wakeBefore->getStatusCode())->toBe(404);

    $snoozed = $controller->snooze(fleetOpsRadarRequest("items/{$key}/snooze", 'POST', ['minutes' => 90, 'reason' => 'Order placed']), $key)->getData(true);
    expect($snoozed['state']['status'])->toBe('snoozed')
        ->and($snoozed['state']['snoozed_until'])->toBe('2026-09-15T10:05:00+00:00')
        ->and($snoozed['state']['snoozed_by_name'])->toBe('Ada Ops')
        ->and($controller->store[$key]->getAttribute('snoozed_by_uuid'))->toBe('user-1')
        ->and($controller->store[$key]->meta['snooze_reason'])->toBe('Order placed');

    $untilTomorrow = $controller->snooze(fleetOpsRadarRequest("items/{$key}/snooze", 'POST', ['until' => '2026-09-16 08:35:00']), $key)->getData(true);
    expect($untilTomorrow['state']['snoozed_until'])->toBe('2026-09-16T08:35:00+00:00');

    $woken = $controller->wake(fleetOpsRadarRequest("items/{$key}/wake", 'POST'), $key)->getData(true);
    expect($woken['state']['status'])->toBe('open')
        ->and($woken['state']['snoozed_until'])->toBeNull();
});

test('assign takes a company user or clears the owner, and plan takes a date or null', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-15 08:35:00', 'UTC'));
    $controller = new FleetOpsRadarControllerProbe(fleetOpsRadarFixtures());
    $key        = 'maintenance_overdue:schedule_oil';

    expect($controller->assign(fleetOpsRadarRequest("items/{$key}/assign", 'POST', ['user' => 'user_stranger']), $key)->getStatusCode())->toBe(422);

    $assigned = $controller->assign(fleetOpsRadarRequest("items/{$key}/assign", 'POST', ['user' => 'user_bo']), $key)->getData(true);
    expect($assigned['state']['assigned_to'])->toBe(['uuid' => 'user-2', 'public_id' => 'user_2', 'name' => 'Bo Tran', 'initials' => 'BT']);

    $cleared = $controller->assign(fleetOpsRadarRequest("items/{$key}/assign", 'POST'), $key)->getData(true);
    expect($cleared['state']['assigned_to'])->toBeNull()
        ->and($controller->store[$key]->getAttribute('assigned_to_uuid'))->toBeNull();

    expect($controller->plan(fleetOpsRadarRequest("items/{$key}/plan", 'POST', ['planned_at' => 'garbage']), $key)->getStatusCode())->toBe(422);

    $planned = $controller->plan(fleetOpsRadarRequest("items/{$key}/plan", 'POST', ['planned_at' => '2026-09-15 14:00:00']), $key)->getData(true);
    expect($planned['state']['planned_at'])->toBe('2026-09-15T14:00:00+00:00');

    $unplanned = $controller->plan(fleetOpsRadarRequest("items/{$key}/plan", 'POST'), $key)->getData(true);
    expect($unplanned['state']['planned_at'])->toBeNull();
});

test('only notices resolve by hand', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-15 08:35:00', 'UTC'));
    $controller = new FleetOpsRadarControllerProbe(fleetOpsRadarFixtures());

    expect($controller->resolve(fleetOpsRadarRequest('items/issue_open:issue_1/resolve', 'POST'), 'issue_open:issue_1')->getStatusCode())->toBe(422);

    // A notice with no row yet is not resolvable either; it needs the row a
    // notice is created with.
    expect($controller->resolve(fleetOpsRadarRequest('items/notice:alert_yard/resolve', 'POST'), 'notice:alert_yard')->getStatusCode())->toBe(404);

    $controller->store['notice:alert_yard'] = new FleetOpsRadarAlertSpy(['uuid' => 'n1', 'public_id' => 'alert_yard', 'type' => 'radar_notice', 'status' => 'open', 'context' => ['key' => 'notice:alert_yard']]);
    $resolved                               = $controller->resolve(fleetOpsRadarRequest('items/notice:alert_yard/resolve', 'POST', ['resolution' => 'Trailers moved']), 'notice:alert_yard')->getData(true);

    expect($resolved['state']['status'])->toBe('resolved')
        ->and($resolved['state']['resolution'])->toBe('Trailers moved')
        ->and($resolved['state']['resolved_by_name'])->toBe('Ada Ops')
        ->and($controller->store['notice:alert_yard']->getAttribute('resolved_by_uuid'))->toBe('user-1');
});

test('bulk applies one state action to many keys and reports the ones it could not find', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-15 08:35:00', 'UTC'));
    $controller = new FleetOpsRadarControllerProbe(fleetOpsRadarFixtures());

    expect($controller->bulk(fleetOpsRadarRequest('items/bulk', 'POST', ['keys' => [], 'action' => 'acknowledge']))->getStatusCode())->toBe(422)
        ->and($controller->bulk(fleetOpsRadarRequest('items/bulk', 'POST', ['keys' => ['issue_open:issue_1'], 'action' => 'delete']))->getStatusCode())->toBe(422)
        ->and($controller->bulk(fleetOpsRadarRequest('items/bulk', 'POST', ['keys' => ['issue_open:issue_1'], 'action' => 'snooze']))->getStatusCode())->toBe(422)
        ->and($controller->bulk(fleetOpsRadarRequest('items/bulk', 'POST', ['keys' => ['issue_open:issue_1'], 'action' => 'assign', 'user' => 'user_stranger']))->getStatusCode())->toBe(422);

    $payload = $controller->bulk(fleetOpsRadarRequest('items/bulk', 'POST', [
        'keys'   => ['issue_open:issue_1', 'part_low_stock:part_pads', 'issue_open:issue_404'],
        'action' => 'snooze',
        'minutes'=> 60,
    ]))->getData(true);

    expect(array_column($payload['results'], 'ok'))->toBe([true, true, false])
        ->and($payload['results'][0]['state']['status'])->toBe('snoozed')
        ->and($payload['results'][2]['error'])->toBe('not found')
        ->and($controller->store)->toHaveCount(2);

    $woken = $controller->bulk(fleetOpsRadarRequest('items/bulk', 'POST', ['keys' => ['issue_open:issue_1'], 'action' => 'wake']))->getData(true);
    expect($woken['results'][0]['state']['status'])->toBe('open');

    $assigned = $controller->bulk(fleetOpsRadarRequest('items/bulk', 'POST', ['keys' => ['issue_open:issue_1'], 'action' => 'assign', 'user' => 'user_bo']))->getData(true);
    expect($assigned['results'][0]['state']['assigned_to']['name'])->toBe('Bo Tran');
});

test('a notice is written with its key, due date and author, and can be deleted', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-15 08:35:00', 'UTC'));
    $controller = new FleetOpsRadarControllerProbe(fleetOpsRadarFixtures());

    expect($controller->storeNotice(fleetOpsRadarRequest('notices', 'POST', ['message' => '   ']))->getStatusCode())->toBe(422)
        ->and($controller->storeNotice(fleetOpsRadarRequest('notices', 'POST', ['message' => 'x', 'due_at' => 'garbage']))->getStatusCode())->toBe(422);

    $response = $controller->storeNotice(fleetOpsRadarRequest('notices', 'POST', [
        'message'  => 'Yard closed Saturday for repaving — move trailers by Fri 18:00',
        'severity' => 'warning',
        'due_at'   => '2026-09-18 18:00:00',
        'scope'    => 'Yard 3 · whole fleet',
    ]));
    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(201)
        ->and($payload['item']['key'])->toBe('notice:alert_notice')
        ->and($payload['item']['severity'])->toBe('warning')
        ->and($payload['item']['due_bucket'])->toBe('week')
        ->and($payload['item']['meta_line'])->toBe('Yard 3 · whole fleet')
        ->and($controller->lastNotice->context['key'])->toBe('notice:alert_notice')
        ->and($controller->lastNotice->meta['created_by_name'])->toBe('Ada Ops');

    expect($controller->destroyNotice(fleetOpsRadarRequest('notices/alert_other', 'DELETE'), 'alert_other')->getStatusCode())->toBe(404)
        ->and($controller->destroyNotice(fleetOpsRadarRequest('notices/alert_notice', 'DELETE'), 'alert_notice')->getData(true))->toBe(['deleted' => true])
        ->and($controller->lastNotice->calls)->toContain(['delete']);
});
