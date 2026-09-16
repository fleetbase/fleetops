<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Fleetbase\FleetOps\Models\InspectionLink;
use Fleetbase\FleetOps\Models\InspectionSubmission;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Part;
use Fleetbase\FleetOps\Models\Trailer;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\FleetOps\Support\LiveOrderQuery;
use Fleetbase\FleetOps\Support\Radar\RadarAgenda;
use Fleetbase\FleetOps\Support\Radar\RadarBriefing;
use Fleetbase\FleetOps\Support\Radar\RadarItemState;
use Fleetbase\FleetOps\Support\Radar\RadarRules;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Alert;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\ScheduleItem;
use Fleetbase\Models\Setting;
use Fleetbase\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Radar: the per-record gaps across resources, maintenance, inspections,
 * staffing, compliance, fuel and parts, as one triage list.
 *
 * Items are computed on every request from the resource tables (see
 * RadarRules); the only thing stored is what someone did about an item
 * (RadarItemState, on the core alerts table). Each source is loaded in its
 * own try/catch so one failing table degrades the page instead of blanking it.
 */
class RadarController extends Controller
{
    public const RESOLVED_WINDOW_DAYS = 7;
    public const SNOOZE_MAX_DAYS      = 90;

    /**
     * The list: items for a status tab, narrowed by pills, a fleet and a
     * query, paged.
     */
    public function items(Request $request): JsonResponse
    {
        $company = $this->companyUuid($request);
        $now     = $this->now();
        $tab     = in_array($request->input('status'), ['open', 'snoozed', 'resolved'], true) ? $request->input('status') : 'open';
        $pills   = array_values(array_filter(explode(',', (string) $request->input('filters', ''))));

        $built = $this->buildItems($company, $now, true);

        if ($tab === 'resolved') {
            $items = $this->resolvedSince($company, $now->copy()->subDays(self::RESOLVED_WINDOW_DAYS), $now);
        } else {
            $items = RadarRules::forTab($built['items'], $tab, $now);
        }

        $items = RadarRules::forPills($items, $pills);
        $items = RadarRules::forCategory($items, $request->input('category'));
        $items = RadarRules::forFleet($items, $request->input('fleet'));
        $items = RadarRules::search($items, $request->input('query'));
        if ($request->input('assigned') === 'me') {
            $items = RadarRules::forAssignee($items, $this->actor($request)?->uuid);
        }
        $page  = RadarRules::paginate($items, (int) $request->input('page', 1), (int) $request->input('limit', 50));

        return response()->json([
            'items'           => $page['items'],
            'groups'          => RadarRules::group($page['items']),
            'meta'            => $page['meta'],
            'counts'          => $built['counts'],
            'summary'         => $built['summary'] + ['resolved' => $tab === 'resolved' ? count($items) : null],
            'snooze_schedule' => $this->snoozeSchedule($company, $now),
            'generated_at'    => $now->toIso8601String(),
            'sources'         => $built['errors'],
        ]);
    }

    /**
     * The numbers the header and the dashboard widget show.
     */
    public function summary(Request $request): JsonResponse
    {
        $company = $this->companyUuid($request);
        $now     = $this->now();
        $built   = $this->buildItems($company, $now, false);

        return response()->json([
            'summary'      => $built['summary'],
            'counts'       => $built['counts'],
            'generated_at' => $now->toIso8601String(),
        ]);
    }

    /**
     * The morning brief: score, category rows, a few sentences and the
     * decisions that can be made in one click.
     */
    public function briefing(Request $request): JsonResponse
    {
        $company = $this->companyUuid($request);
        $now     = $this->now();
        $built   = $this->buildItems($company, $now, false);
        $history = $this->scoreHistory($company);
        $brief   = RadarBriefing::build($built['items'], $now, $history);

        $this->rememberScore($company, RadarBriefing::pushHistory($history, $brief['score']['value'], $now));

        $since    = $now->copy()->subDay();
        $resolved = $this->resolvedSince($company, $since, $now);
        $rolled   = array_filter($built['items'], function ($item) use ($since, $now) {
            $triggered = RadarRules::carbon($item['state']['triggered_at'] ?? null);

            return RadarRules::isOpen($item, $now) && $triggered && $triggered->lt($since);
        });

        return response()->json($brief + [
            'yesterday'    => ['closed' => count($resolved), 'rolled_over' => count($rolled)],
            'generated_at' => $now->toIso8601String(),
            'sources'      => $built['errors'],
        ]);
    }

    /**
     * The Agenda view: items on a time scale, with the roster's shifts and
     * the handover cards.
     */
    public function agenda(Request $request): JsonResponse
    {
        $company = $this->companyUuid($request);
        $now     = $this->now();
        $window  = (string) $request->input('window', '24h');
        $built   = $this->buildItems($company, $now, false);
        $items   = RadarRules::forFleet($built['items'], $request->input('fleet'));
        $shifts  = $built['sources']['shifts'] ?? [];
        $drivers = $built['sources']['drivers'] ?? [];
        $orders  = $this->loadOrdersForHandovers($company, $items);

        return response()->json(RadarAgenda::build($items, $shifts, $window, $now, $drivers, $orders) + [
            'summary'      => $built['summary'],
            'generated_at' => $now->toIso8601String(),
            'sources'      => $built['errors'],
        ]);
    }

    /**
     * One handover card, for the list view's Cover / Reassign actions.
     */
    public function handoverSuggest(Request $request, string $key): JsonResponse
    {
        $company = $this->companyUuid($request);
        $now     = $this->now();
        $built   = $this->buildItems($company, $now, false);
        $orders  = $this->loadOrdersForHandovers($company, $built['items']);
        $cards   = RadarAgenda::handovers(
            array_values(array_filter($built['items'], fn ($item) => $item['key'] === $key && RadarRules::isOpen($item, $now))),
            $built['sources']['shifts'] ?? [],
            $built['sources']['drivers'] ?? [],
            $orders,
            $now,
            ['shift_handover', 'shift_late_start']
        );

        if (!$cards) {
            return response()->json(['error' => 'No handover is open for that item.'], 404);
        }

        return response()->json(['handover' => $cards[0], 'generated_at' => $now->toIso8601String()]);
    }

    /**
     * Push a shift's end out by some minutes, for "Extend shift 1h".
     */
    public function extendShift(Request $request, string $id): JsonResponse
    {
        $minutes = (int) $request->input('minutes', 60);
        if ($minutes < 1 || $minutes > 24 * 60) {
            return response()->json(['error' => 'minutes must be between 1 and 1440.'], 422);
        }

        $company = $this->companyUuid($request);
        $shift   = $this->findShift($company, $id);
        if (!$shift) {
            return response()->json(['error' => 'Shift not found.'], 404);
        }

        $end           = RadarRules::carbon($shift->end_at) ?? $this->now();
        $shift->end_at = $end->copy()->addMinutes($minutes);
        $this->saveShift($shift);

        return response()->json([
            'shift' => [
                'uuid'      => $shift->uuid,
                'public_id' => $shift->public_id,
                'start_at'  => RadarRules::carbon($shift->start_at)?->toIso8601String(),
                'end_at'    => RadarRules::carbon($shift->end_at)?->toIso8601String(),
                'status'    => $shift->status,
            ],
            'generated_at' => $this->now()->toIso8601String(),
        ]);
    }

    public function acknowledge(Request $request, string $key): JsonResponse
    {
        return $this->act($request, $key, function (Alert $row, array $item) use ($request) {
            $row->acknowledge($this->actor($request));
        });
    }

    public function snooze(Request $request, string $key): JsonResponse
    {
        $minutes = $this->snoozeMinutes($request);
        if ($minutes === null) {
            return response()->json(['error' => 'Pass minutes (1 to ' . (self::SNOOZE_MAX_DAYS * 1440) . ') or a future until date.'], 422);
        }

        return $this->act($request, $key, function (Alert $row) use ($request, $minutes) {
            $row->snooze($minutes, $request->input('reason'), $this->actor($request));
        });
    }

    public function wake(Request $request, string $key): JsonResponse
    {
        return $this->act($request, $key, function (Alert $row) {
            $row->unsnooze();
        }, false);
    }

    public function assign(Request $request, string $key): JsonResponse
    {
        $company  = $this->companyUuid($request);
        $assignee = null;

        if ($request->filled('user')) {
            $assignee = $this->findCompanyUser($company, (string) $request->input('user'));
            if (!$assignee) {
                return response()->json(['error' => 'That user is not a member of this company.'], 422);
            }
        }

        return $this->act($request, $key, function (Alert $row) use ($assignee) {
            $row->assignTo($assignee);
        });
    }

    public function plan(Request $request, string $key): JsonResponse
    {
        $plannedAt = null;
        if ($request->filled('planned_at')) {
            $plannedAt = RadarRules::carbon($request->input('planned_at'));
            if (!$plannedAt) {
                return response()->json(['error' => 'planned_at must be a date.'], 422);
            }
        }

        return $this->act($request, $key, function (Alert $row) use ($plannedAt) {
            $row->update(['planned_at' => $plannedAt]);
        });
    }

    /**
     * Only a notice can be resolved by hand: every other item resolves when
     * the record it describes changes.
     */
    public function resolve(Request $request, string $key): JsonResponse
    {
        $parsed = RadarRules::parseKey($key);
        if (!$parsed || $parsed[0] !== 'notice') {
            return response()->json(['error' => 'Only notices resolve by hand; other items close when their record changes.'], 422);
        }

        return $this->act($request, $key, function (Alert $row) use ($request) {
            $row->resolve($this->actor($request), $request->input('resolution'));
        }, false);
    }

    /**
     * One state action over many keys. Record-level actions stay one at a time.
     */
    public function bulk(Request $request): JsonResponse
    {
        $keys   = array_values(array_filter((array) $request->input('keys', []), 'is_string'));
        $action = (string) $request->input('action');

        if (!$keys) {
            return response()->json(['error' => 'Pass at least one key.'], 422);
        }
        if (!in_array($action, ['acknowledge', 'snooze', 'wake', 'assign', 'plan'], true)) {
            return response()->json(['error' => 'action must be acknowledge, snooze, wake, assign or plan.'], 422);
        }

        $company  = $this->companyUuid($request);
        $now      = $this->now();
        $built    = $this->buildItems($company, $now, false);
        $byKey    = array_column($built['items'], null, 'key');
        $actor    = $this->actor($request);
        $minutes  = $action === 'snooze' ? $this->snoozeMinutes($request) : null;
        $assignee = null;
        $planned  = null;

        if ($action === 'snooze' && $minutes === null) {
            return response()->json(['error' => 'Pass minutes or a future until date.'], 422);
        }
        if ($action === 'assign' && $request->filled('user')) {
            $assignee = $this->findCompanyUser($company, (string) $request->input('user'));
            if (!$assignee) {
                return response()->json(['error' => 'That user is not a member of this company.'], 422);
            }
        }
        if ($action === 'plan' && $request->filled('planned_at')) {
            $planned = RadarRules::carbon($request->input('planned_at'));
        }

        $results = [];
        foreach ($keys as $key) {
            $item = $byKey[$key] ?? null;
            $row  = $item ? $this->rowFor($company, $item) : $this->findRow($company, $key);

            if (!$row) {
                $results[] = ['key' => $key, 'ok' => false, 'error' => 'not found'];
                continue;
            }

            match ($action) {
                'acknowledge' => $row->acknowledge($actor),
                'snooze'      => $row->snooze($minutes, $request->input('reason'), $actor),
                'wake'        => $row->unsnooze(),
                'assign'      => $row->assignTo($assignee),
                'plan'        => $row->update(['planned_at' => $planned]),
            };

            $results[] = ['key' => $key, 'ok' => true, 'state' => $this->stateOf($row, $now)];
        }

        return response()->json(['results' => $results, 'generated_at' => $now->toIso8601String()]);
    }

    /**
     * A notice is an item a person writes: a yard closure, a deadline for
     * the whole fleet.
     */
    public function storeNotice(Request $request): JsonResponse
    {
        $message  = trim((string) $request->input('message'));
        $severity = in_array($request->input('severity'), [RadarRules::SEVERITY_CRITICAL, RadarRules::SEVERITY_WARNING, RadarRules::SEVERITY_INFO], true) ? $request->input('severity') : RadarRules::SEVERITY_INFO;
        $dueAt    = $request->filled('due_at') ? RadarRules::carbon($request->input('due_at')) : null;

        if ($message === '') {
            return response()->json(['error' => 'A notice needs a message.'], 422);
        }
        if ($request->filled('due_at') && !$dueAt) {
            return response()->json(['error' => 'due_at must be a date.'], 422);
        }

        $company = $this->companyUuid($request);
        $now     = $this->now();
        $actor   = $this->actor($request);

        $alert = $this->createNotice([
            'company_uuid' => $company,
            'type'         => RadarRules::NOTICE_TYPE,
            'severity'     => $severity,
            'status'       => 'open',
            'message'      => $message,
            'triggered_at' => $now,
            'meta'         => [
                'due_at'          => $dueAt?->toIso8601String(),
                'scope'           => $request->input('scope') ? trim((string) $request->input('scope')) : null,
                'created_by_uuid' => $actor?->uuid,
                'created_by_name' => $actor?->name,
            ],
        ]);

        $alert->context = ['key' => RadarRules::keyFor('notice', $alert->public_id ?? $alert->uuid), 'rule' => 'notice', 'category' => RadarRules::CATEGORY_NOTICES, 'chip' => 'Notice', 'due_at' => $dueAt?->toIso8601String()];
        $alert->save();

        $items = RadarRules::noticeItems($this->notices($company), $now);
        $items = RadarRules::mergeStates($items, [], $now);
        $item  = collect($items)->firstWhere('source.uuid', $alert->uuid);

        return response()->json(['item' => $item, 'generated_at' => $now->toIso8601String()], 201);
    }

    public function destroyNotice(Request $request, string $id): JsonResponse
    {
        $company = $this->companyUuid($request);
        $alert   = $this->findNotice($company, $id);

        if (!$alert) {
            return response()->json(['error' => 'Notice not found.'], 404);
        }

        $alert->delete();

        return response()->json(['deleted' => true]);
    }

    // ------------------------------------------------------------------
    // Building
    // ------------------------------------------------------------------

    /**
     * Load every source, run the rules, attach state, and (for the list)
     * close the rows whose gap is gone.
     *
     * @return array{items: array, counts: array, summary: array, errors: array}
     */
    protected function buildItems(?string $company, Carbon $now, bool $reconcile): array
    {
        $errors  = [];
        $sources = [];

        foreach ($this->sourceLoaders() as $name => $loader) {
            try {
                $sources[$name] = $loader($company, $now, $sources);
            } catch (\Throwable $e) {
                $sources[$name] = [];
                $errors[$name]  = $e->getMessage();
                if (function_exists('report')) {
                    report($e);
                }
            }
        }

        try {
            $sources['states'] = $this->statesFor($company);
        } catch (\Throwable $e) {
            $sources['states'] = [];
            $errors['states']  = $e->getMessage();
        }

        $built            = RadarRules::build($sources, $now);
        $built['sources'] = $sources;

        if ($reconcile && empty($errors)) {
            try {
                $this->reconcile($company, array_column($built['items'], 'key'));
            } catch (\Throwable $e) {
                $errors['reconcile'] = $e->getMessage();
            }
        }

        $built['errors'] = $errors;

        return $built;
    }

    /**
     * Source name => loader. Order matters where one loader reads another's
     * rows (shifts read drivers, fuel reads vehicles).
     *
     * @return array<string, callable(?string, Carbon, array): array>
     */
    protected function sourceLoaders(): array
    {
        return [
            'schedules'             => fn ($company, $now) => $this->loadSchedules($company, $now),
            'workOrders'            => fn ($company, $now) => $this->loadWorkOrders($company, $now),
            'issues'                => fn ($company, $now) => $this->loadIssues($company, $now),
            'drivers'               => fn ($company, $now) => $this->loadDrivers($company, $now),
            'shifts'                => fn ($company, $now, $sources) => $this->loadShifts($company, $now, $sources['drivers'] ?? []),
            'vehicles'              => fn ($company, $now) => $this->loadVehicles($company, $now),
            'trailers'              => fn ($company, $now) => $this->loadTrailers($company, $now),
            'devices'               => fn ($company, $now) => $this->loadDevices($company, $now),
            'parts'                 => fn ($company, $now) => $this->loadParts($company, $now),
            'fuelTransactions'      => fn ($company, $now, $sources) => $this->loadFuelTransactions($company, $now, $sources['vehicles'] ?? []),
            'inspectionSubmissions' => fn ($company, $now) => $this->loadInspectionSubmissions($company, $now),
            'inspectionLinks'       => fn ($company, $now) => $this->loadInspectionLinks($company, $now),
            'notices'               => fn ($company) => $this->notices($company),
        ];
    }

    protected function loadSchedules(?string $company, Carbon $now): array
    {
        return $this->scoped(MaintenanceSchedule::query(), $company)
            ->where('status', 'active')
            ->whereNotNull('next_due_date')
            ->where('next_due_date', '<=', $now->copy()->addDays(RadarRules::DUE_SOON_DAYS))
            ->with('subject')
            ->withCount(['workOrders as open_work_orders_count' => fn ($query) => $query->whereNotIn('status', RadarRules::WORK_ORDER_CLOSED)])
            ->get()
            ->map(fn (MaintenanceSchedule $schedule) => [
                'uuid'                => $schedule->uuid,
                'public_id'           => $schedule->public_id,
                'name'                => $schedule->name,
                'type'                => $schedule->type,
                'status'              => $schedule->status,
                'next_due_date'       => $schedule->next_due_date,
                'next_due_odometer'   => $schedule->next_due_odometer,
                'has_open_work_order' => (int) ($schedule->open_work_orders_count ?? 0) > 0,
                'subject'             => $this->subjectFor($schedule->subject),
            ])
            ->all();
    }

    protected function loadWorkOrders(?string $company, Carbon $now): array
    {
        return $this->scoped(WorkOrder::query(), $company)
            ->whereNotIn('status', RadarRules::WORK_ORDER_CLOSED)
            ->where(function ($query) use ($now) {
                $query->where(fn ($due) => $due->whereNotNull('due_at')->where('due_at', '<', $now))
                    ->orWhereIn('status', RadarRules::WORK_ORDER_BLOCKED);
            })
            ->with('target')
            ->get()
            ->map(fn (WorkOrder $workOrder) => [
                'uuid'      => $workOrder->uuid,
                'public_id' => $workOrder->public_id,
                'code'      => $workOrder->code,
                'subject'   => $workOrder->subject,
                'status'    => $workOrder->status,
                'priority'  => $workOrder->priority,
                'due_at'    => $workOrder->due_at,
                'checklist' => $workOrder->checklist,
                'target'    => $this->subjectFor($workOrder->target),
            ])
            ->all();
    }

    protected function loadIssues(?string $company, Carbon $now): array
    {
        return $this->scoped(Issue::query(), $company)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->with(['vehicle', 'driver.user'])
            ->get()
            ->map(fn (Issue $issue) => [
                'uuid'          => $issue->uuid,
                'public_id'     => $issue->public_id,
                'title'         => $issue->title,
                'report'        => $issue->report,
                'priority'      => $issue->priority,
                'status'        => $issue->status,
                'meta'          => $issue->meta ?? [],
                'reporter_name' => $issue->reporter_name,
                'vehicle'       => $this->subjectFor($issue->vehicle),
                'driver'        => $this->subjectFor($issue->driver),
            ])
            ->all();
    }

    protected function loadDrivers(?string $company, Carbon $now): array
    {
        return $this->scoped(Driver::query(), $company)
            ->with(['user'])
            ->withCount(['orders as active_orders_count' => fn ($query) => $query->whereNotIn('status', LiveOrderQuery::$baseExcludedStatuses)])
            ->get()
            ->map(fn (Driver $driver) => [
                'class'                  => Driver::class,
                'uuid'                   => $driver->uuid,
                'public_id'              => $driver->public_id,
                'name'                   => $driver->name,
                'phone'                  => $driver->phone,
                'photo_url'              => $driver->photo_url,
                'online'                 => (bool) $driver->online,
                'status'                 => $driver->status,
                'vehicle_uuid'           => $driver->vehicle_uuid,
                'license_expiry'         => $driver->license_expiry,
                'drivers_license_number' => $driver->drivers_license_number,
                'active_orders'          => (int) ($driver->active_orders_count ?? 0),
                'location'               => $this->pointFor($driver->location),
            ])
            ->all();
    }

    protected function loadShifts(?string $company, Carbon $now, array $drivers): array
    {
        $byUuid = array_column($drivers, null, 'uuid');
        if (!$byUuid) {
            return [];
        }

        return ScheduleItem::query()
            ->whereIn('assignee_type', ['fleet-ops:driver', Driver::class])
            ->whereIn('assignee_uuid', array_keys($byUuid))
            ->whereIn('status', Driver::LIVE_SHIFT_STATUSES)
            ->where('end_at', '>', $now)
            ->where('start_at', '<=', $now->copy()->addHours(24))
            ->orderBy('start_at')
            ->get()
            ->map(function (ScheduleItem $shift) use ($byUuid) {
                $driver = $byUuid[$shift->assignee_uuid] ?? null;

                return [
                    'uuid'                => $shift->uuid,
                    'public_id'           => $shift->public_id,
                    'start_at'            => $shift->start_at,
                    'end_at'              => $shift->end_at,
                    'status'              => $shift->status,
                    'driver'              => $driver ? ['type' => 'driver', 'class' => Driver::class, 'uuid' => $driver['uuid'], 'public_id' => $driver['public_id'], 'label' => $driver['name'], 'photo_url' => $driver['photo_url'], 'phone' => $driver['phone']] : null,
                    'driver_online'       => (bool) ($driver['online'] ?? false),
                    'driver_vehicle_uuid' => $driver['vehicle_uuid'] ?? null,
                    'active_orders'       => (int) ($driver['active_orders'] ?? 0),
                ];
            })
            ->all();
    }

    protected function loadVehicles(?string $company, Carbon $now): array
    {
        return $this->scoped(Vehicle::query(), $company)
            ->with(['driver'])
            ->withCount('devices')
            ->get()
            ->map(fn (Vehicle $vehicle) => [
                'class'            => Vehicle::class,
                'uuid'             => $vehicle->uuid,
                'public_id'        => $vehicle->public_id,
                'label'            => $vehicle->display_name,
                'photo_url'        => $vehicle->photo_url,
                'status'           => $vehicle->status,
                'driver_uuid'      => $vehicle->driver?->uuid,
                'device_count'     => (int) ($vehicle->devices_count ?? 0),
                'lease_expires_at' => $vehicle->lease_expires_at,
                'plate_number'     => $vehicle->plate_number,
                'vin'              => $vehicle->vin,
                'fuel_card_number' => $vehicle->fuel_card_number,
            ])
            ->all();
    }

    protected function loadTrailers(?string $company, Carbon $now): array
    {
        return $this->scoped(Trailer::query(), $company)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', $now->copy()->addDays(RadarRules::EXPIRING_DAYS))
            ->get()
            ->map(fn (Trailer $trailer) => [
                'class'            => Trailer::class,
                'uuid'             => $trailer->uuid,
                'public_id'        => $trailer->public_id,
                'label'            => $trailer->display_name,
                'photo_url'        => $trailer->photo_url,
                'status'           => $trailer->status,
                'lease_expires_at' => $trailer->lease_expires_at,
            ])
            ->all();
    }

    protected function loadDevices(?string $company, Carbon $now): array
    {
        return $this->scoped(Device::query(), $company)
            ->whereNull('attachable_uuid')
            ->get()
            ->map(fn (Device $device) => [
                'class'           => Device::class,
                'uuid'            => $device->uuid,
                'public_id'       => $device->public_id,
                'name'            => $device->name,
                'device_id'       => $device->device_id,
                'attachable_uuid' => $device->attachable_uuid,
                'online'          => (bool) $device->is_online,
            ])
            ->all();
    }

    protected function loadParts(?string $company, Carbon $now): array
    {
        return $this->scoped(Part::query(), $company)
            ->get()
            ->map(fn (Part $part) => [
                'class'            => Part::class,
                'uuid'             => $part->uuid,
                'public_id'        => $part->public_id,
                'name'             => $part->name,
                'sku'              => $part->sku,
                'quantity_on_hand' => (int) $part->quantity_on_hand,
                'reorder_point'    => $part->reorder_point,
                'specs'            => $part->specs,
                'photo_url'        => $part->photo_url ?? null,
            ])
            ->all();
    }

    protected function loadFuelTransactions(?string $company, Carbon $now, array $vehicles): array
    {
        return $this->scoped(FuelProviderTransaction::query(), $company)
            ->where('sync_status', 'unmatched')
            ->get()
            ->map(function (FuelProviderTransaction $transaction) use ($vehicles) {
                $row = [
                    'class'           => FuelProviderTransaction::class,
                    'uuid'            => $transaction->uuid,
                    'public_id'       => $transaction->public_id,
                    'amount'          => $transaction->amount,
                    'currency'        => $transaction->currency,
                    'station_name'    => $transaction->station_name,
                    'provider'        => $transaction->provider,
                    'transaction_at'  => $transaction->transaction_at,
                    'vehicle_card_id' => $transaction->vehicle_card_id,
                    'plate_number'    => $transaction->plate_number,
                    'vin'             => $transaction->vin,
                    'sync_status'     => $transaction->sync_status,
                ];
                $row['suggested_vehicle'] = RadarRules::suggestVehicleForFuel($row, $vehicles);

                return $row;
            })
            ->all();
    }

    protected function loadInspectionSubmissions(?string $company, Carbon $now): array
    {
        return $this->scoped(InspectionSubmission::query(), $company)
            ->where(function ($query) use ($now) {
                $query->where(function ($failed) {
                    $failed->where('result', 'failed')->whereNull('resolved_at')->where('status', '!=', 'resolved');
                })->orWhere(function ($draft) use ($now) {
                    $draft->where('status', 'draft')->where('started_at', '<', $now->copy()->subHours(RadarRules::STALE_DRAFT_HOURS));
                });
            })
            ->with(['form', 'vehicle', 'driver.user', 'failedItemResults'])
            ->get()
            ->map(function (InspectionSubmission $submission) {
                $severities = $submission->failedItemResults->pluck('severity')->map(fn ($severity) => strtolower((string) $severity))->all();
                $rank       = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
                $highest    = null;
                foreach ($severities as $severity) {
                    if (($rank[$severity] ?? 0) > ($rank[$highest] ?? 0)) {
                        $highest = $severity;
                    }
                }

                return [
                    'uuid'               => $submission->uuid,
                    'public_id'          => $submission->public_id,
                    'status'             => $submission->status,
                    'result'             => $submission->result,
                    'failed_items'       => (int) $submission->failed_items,
                    'total_items'        => (int) $submission->total_items,
                    'issue_uuid'         => $submission->issue_uuid,
                    'work_order_uuid'    => $submission->work_order_uuid,
                    'resolved_at'        => $submission->resolved_at,
                    'started_at'         => $submission->started_at,
                    'submitted_at'       => $submission->submitted_at,
                    'created_at'         => $submission->created_at,
                    'form_name'          => $submission->form?->name,
                    'driver_name'        => $submission->driver_name,
                    'highest_severity'   => $highest ?? ($submission->failed_items > 0 ? 'high' : 'low'),
                    'failed_item_labels' => $submission->failedItemResults->map(fn ($result) => ['label' => $result->label, 'severity' => $result->severity, 'category' => $result->category])->values()->all(),
                    'vehicle'            => $this->subjectFor($submission->vehicle),
                    'driver'             => $this->subjectFor($submission->driver),
                ];
            })
            ->all();
    }

    protected function loadInspectionLinks(?string $company, Carbon $now): array
    {
        return $this->scoped(InspectionLink::query(), $company)
            ->where('status', 'active')
            ->whereNull('used_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now->copy()->addHours(RadarRules::LINK_EXPIRY_HOURS))
            ->with(['form', 'driver.user', 'vehicle'])
            ->get()
            ->map(fn (InspectionLink $link) => [
                'class'          => InspectionLink::class,
                'uuid'           => $link->uuid,
                'public_id'      => $link->public_id,
                'status'         => $link->status,
                'used_at'        => $link->used_at,
                'expires_at'     => $link->expires_at,
                'last_viewed_at' => $link->last_viewed_at,
                'form_uuid'      => $link->inspection_form_uuid,
                'form_public_id' => $link->form?->public_id,
                'form_name'      => $link->form?->name,
                'driver'         => $this->subjectFor($link->driver),
                'vehicle'        => $this->subjectFor($link->vehicle),
            ])
            ->all();
    }

    // ------------------------------------------------------------------
    // Acting
    // ------------------------------------------------------------------

    /**
     * Run a state mutation for one key and answer with the item as it now
     * reads. When `$needsItem` is false the row must already exist (wake,
     * resolve); otherwise the row is created from the live item.
     */
    protected function act(Request $request, string $key, callable $mutate, bool $needsItem = true): JsonResponse
    {
        if (!RadarRules::parseKey($key)) {
            return response()->json(['error' => 'Unknown item key.'], 404);
        }

        $company = $this->companyUuid($request);
        $now     = $this->now();
        $built   = $this->buildItems($company, $now, false);
        $item    = collect($built['items'])->firstWhere('key', $key);
        $row     = null;

        if ($item) {
            $row = $needsItem ? $this->rowFor($company, $item) : $this->findRow($company, $key);
        } elseif (!$needsItem) {
            $row = $this->findRow($company, $key);
        }

        if (!$row) {
            return response()->json(['error' => $item ? 'This item has no state yet.' : 'Item not found.'], 404);
        }

        $mutate($row, $item ?? []);

        $state = $this->stateOf($row, $now);
        if ($item) {
            $item['state'] = $state;
        }

        return response()->json([
            'item'         => $item,
            'state'        => $state,
            'generated_at' => $now->toIso8601String(),
        ]);
    }

    /**
     * Minutes from either `minutes` or a future `until`, or null when neither is usable.
     */
    protected function snoozeMinutes(Request $request): ?int
    {
        $max = self::SNOOZE_MAX_DAYS * 1440;

        if ($request->filled('minutes')) {
            $minutes = (int) $request->input('minutes');

            return $minutes >= 1 && $minutes <= $max ? $minutes : null;
        }

        if ($request->filled('until')) {
            $until = RadarRules::carbon($request->input('until'));
            if (!$until || $until->lte($this->now())) {
                return null;
            }
            $minutes = (int) ceil($this->now()->diffInMinutes($until));

            return $minutes >= 1 && $minutes <= $max ? $minutes : null;
        }

        return null;
    }

    /**
     * A user of this company by uuid or public id.
     */
    protected function findCompanyUser(?string $company, string $id): ?User
    {
        $user = User::query()
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->first();

        if (!$user) {
            return null;
        }

        $isMember = $user->company_uuid === $company
            || CompanyUser::query()->where('company_uuid', $company)->where('user_uuid', $user->uuid)->exists();

        return $isMember ? $user : null;
    }

    /**
     * Active orders for the drivers with a handover open, keyed by driver
     * uuid: what the cover driver would take over.
     *
     * @return array<string, array>
     */
    protected function loadOrdersForHandovers(?string $company, array $items): array
    {
        $driverUuids = [];
        foreach ($items as $item) {
            if (in_array($item['rule'] ?? null, ['shift_handover', 'shift_late_start'], true) && !empty($item['subject']['uuid'])) {
                $driverUuids[] = $item['subject']['uuid'];
            }
        }
        if (!$driverUuids) {
            return [];
        }

        try {
            $orders = $this->scoped(Order::query(), $company)
                ->whereIn('driver_assigned_uuid', array_unique($driverUuids))
                ->whereNotIn('status', LiveOrderQuery::$baseExcludedStatuses)
                ->with(['payload.dropoff'])
                ->orderBy('scheduled_at')
                ->get();
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }

            return [];
        }

        $byDriver = [];
        foreach ($orders as $order) {
            $dropoff                                  = $order->payload?->dropoff;
            $byDriver[$order->driver_assigned_uuid][] = [
                'uuid'        => $order->uuid,
                'public_id'   => $order->public_id,
                'status'      => $order->status,
                'destination' => $dropoff?->name ?: ($dropoff?->street1 ?: null),
                'ends_at'     => RadarRules::carbon($order->time_window_end ?? $order->scheduled_at)?->toIso8601String(),
            ];
        }

        return $byDriver;
    }

    protected function findShift(?string $company, string $id): ?ScheduleItem
    {
        $driverUuids = $this->scoped(Driver::query(), $company)->pluck('uuid')->all();
        if (!$driverUuids) {
            return null;
        }

        return ScheduleItem::query()
            ->whereIn('assignee_type', ['fleet-ops:driver', Driver::class])
            ->whereIn('assignee_uuid', $driverUuids)
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->first();
    }

    protected function saveShift(ScheduleItem $shift): void
    {
        $shift->save();
    }

    // ------------------------------------------------------------------
    // State (thin wrappers so a test can stand in for the alerts table)
    // ------------------------------------------------------------------

    protected function statesFor(?string $company): array
    {
        return RadarItemState::statesFor($company);
    }

    protected function rowFor(?string $company, array $item): ?Alert
    {
        return RadarItemState::rowFor($company, $item);
    }

    protected function findRow(?string $company, string $key): ?Alert
    {
        return RadarItemState::findRow($company, $key);
    }

    protected function reconcile(?string $company, array $liveKeys): int
    {
        return RadarItemState::reconcile($company, $liveKeys);
    }

    protected function resolvedSince(?string $company, Carbon $since, Carbon $now): array
    {
        return RadarItemState::resolvedSince($company, $since, $now);
    }

    protected function snoozeSchedule(?string $company, Carbon $now): array
    {
        return RadarItemState::snoozeSchedule($company, $now);
    }

    protected function notices(?string $company): array
    {
        return RadarItemState::notices($company);
    }

    /**
     * Past days' scores, kept per company so the brief can say "vs yesterday".
     *
     * @return array<int, array{date: string, score: int}>
     */
    protected function scoreHistory(?string $company): array
    {
        if (!$company) {
            return [];
        }

        try {
            $history = Setting::lookup('company.' . $company . '.fleet-ops.radar.score_history', []);
        } catch (\Throwable) {
            return [];
        }

        return is_array($history) ? array_values($history) : [];
    }

    protected function rememberScore(?string $company, array $history): void
    {
        if (!$company) {
            return;
        }

        try {
            Setting::configure('company.' . $company . '.fleet-ops.radar.score_history', $history);
        } catch (\Throwable) {
            // The brief still renders without a delta.
        }
    }

    protected function createNotice(array $attributes): Alert
    {
        return Alert::create($attributes);
    }

    protected function findNotice(?string $company, string $id): ?Alert
    {
        return Alert::query()
            ->where('company_uuid', $company)
            ->where('type', RadarRules::NOTICE_TYPE)
            ->where(function ($query) use ($id) {
                $query->where('public_id', $id)->orWhere('uuid', $id);
            })
            ->first();
    }

    /**
     * A row's state as the console reads it, after a mutation.
     */
    protected function stateOf(Alert $row, Carbon $now): array
    {
        $fresh = $row->exists ? $row->fresh(['acknowledgedBy', 'assignedTo', 'snoozedBy']) : null;

        return RadarRules::normalizeState(RadarItemState::toState($fresh ?? $row), $now);
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

    protected function now(): Carbon
    {
        return Carbon::now();
    }

    protected function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    protected function scoped(Builder $query, ?string $companyUuid): Builder
    {
        return $query->when($companyUuid, fn ($query) => $query->where($query->qualifyColumn('company_uuid'), $companyUuid));
    }

    protected function companyUuid(Request $request): ?string
    {
        return session('company') ?? $request->user()?->company_uuid ?? $request->user()?->company?->uuid;
    }

    /**
     * A related model as the subject block an item carries, or null.
     */
    protected function subjectFor(?Model $model): ?array
    {
        if (!$model) {
            return null;
        }

        $type = match (true) {
            $model instanceof Vehicle => 'vehicle',
            $model instanceof Trailer => 'trailer',
            $model instanceof Driver  => 'driver',
            $model instanceof Device  => 'device',
            $model instanceof Part    => 'part',
            default                   => strtolower(class_basename($model)),
        };

        $label = match ($type) {
            'driver' => $model->name,
            default  => $model->name ?? ($model->display_name ?? null),
        };

        return [
            'type'      => $type,
            'class'     => get_class($model),
            'uuid'      => $model->uuid,
            'public_id' => $model->public_id ?? null,
            'label'     => $label ?: ($model->public_id ?? $type),
            'photo_url' => $model->photo_url ?? null,
            'phone'     => $type === 'driver' ? ($model->phone ?? null) : null,
        ];
    }

    protected function pointFor(mixed $point): ?array
    {
        if (!$point || !method_exists($point, 'getLat')) {
            return null;
        }

        return ['lat' => $point->getLat(), 'lng' => $point->getLng()];
    }
}
