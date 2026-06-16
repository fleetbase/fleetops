<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\FleetOps\Models\Maintenance;
use Fleetbase\FleetOps\Models\MaintenanceSchedule;
use Fleetbase\FleetOps\Models\Part;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\WorkOrder;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class HubController extends Controller
{
    public function resources(Request $request)
    {
        $company = $this->companyUuid($request);

        $counts = [
            'drivers'                       => $this->count(Driver::query(), $company),
            'vehicles'                      => $this->count(Vehicle::query(), $company),
            'fleets'                        => $this->count(Fleet::query()->whereNull('parent_fleet_uuid'), $company),
            'vendors'                       => $this->count(Vendor::query(), $company),
            'contacts'                      => $this->count(Contact::query(), $company),
            'places'                        => $this->count(Place::query(), $company),
            'issues'                        => $this->count(Issue::query()->whereNotIn('status', ['resolved', 'closed']), $company),
            'drivers_without_vehicles'      => $this->count(Driver::query()->whereNull('vehicle_uuid'), $company),
            'vehicles_without_drivers'      => $this->count(Vehicle::query()->whereDoesntHave('driver'), $company),
            'vehicles_without_devices'      => $this->count(Vehicle::query()->whereDoesntHave('devices'), $company),
            'unattached_devices'            => $this->count(Device::query()->whereNull('attachable_uuid'), $company),
            'resource_issues'               => $this->count(Issue::query()->whereNotIn('status', ['resolved', 'closed'])->where(function ($query) {
                $query->whereNotNull('vehicle_uuid')->orWhereNotNull('driver_uuid');
            }), $company),
            'overdue_vehicle_schedules'     => $this->count(MaintenanceSchedule::query()->where('subject_type', Vehicle::class)->where('status', 'active')->whereNotNull('next_due_date')->where('next_due_date', '<', Carbon::now()), $company),
            'upcoming_vehicle_schedules'    => $this->count(MaintenanceSchedule::query()->where('subject_type', Vehicle::class)->where('status', 'active')->whereBetween('next_due_date', [Carbon::now(), Carbon::now()->addDays(7)]), $company),
            'open_resource_work_orders'     => $this->count(WorkOrder::query()->whereIn('target_type', [Vehicle::class, Equipment::class])->whereIn('status', ['open', 'in_progress']), $company),
            'overdue_resource_work_orders'  => $this->count(WorkOrder::query()->whereIn('target_type', [Vehicle::class, Equipment::class])->whereNotIn('status', ['closed', 'canceled'])->whereNotNull('due_at')->where('due_at', '<', Carbon::now()), $company),
            'low_stock_parts'               => $this->count(Part::query()->where('quantity_on_hand', '>', 0)->where('quantity_on_hand', '<=', 5), $company),
            'unmatched_fuel_transactions'   => $this->count(FuelProviderTransaction::query()->where('sync_status', 'unmatched'), $company),
            'fuel_reports'                  => $this->count(FuelReport::query(), $company),
            'fuel_transactions'             => $this->count(FuelProviderTransaction::query(), $company),
        ];

        $fuelRecords = $counts['fuel_reports'] + $counts['fuel_transactions'];

        return response()->json([
            'kpis'     => [
                $this->kpi('drivers', 'Drivers', $counts['drivers'], $counts['drivers'] > 0 ? 'Driver profiles available for dispatch.' : 'Add driver profiles before dispatching work.', 'blue', 'id-card', 'management.drivers'),
                $this->kpi('vehicles', 'Vehicles', $counts['vehicles'], $counts['vehicles'] > 0 ? 'Vehicles ready for assignment and tracking.' : 'Add vehicles so dispatch has assignable assets.', 'green', 'truck', 'management.vehicles'),
                $this->kpi('issues', 'Open Issues', $counts['issues'], $counts['issues'] > 0 ? 'Issues need review before they affect service quality.' : 'No open issues are visible right now.', $counts['issues'] > 0 ? 'rose' : 'green', 'triangle-exclamation', 'management.issues'),
                $this->kpi('fuel_records', 'Fuel Records', $fuelRecords, $fuelRecords > 0 ? 'Fuel activity is available for review.' : 'Fuel records will appear as reports or transactions arrive.', 'amber', 'gas-pump', $counts['fuel_transactions'] > $counts['fuel_reports'] ? 'management.fuel-transactions' : 'management.fuel-reports'),
            ],
            'actions'  => $this->resourceActions($counts, $fuelRecords),
            'sections' => [
                [
                    'key'         => 'people_assets',
                    'title'       => 'People And Assets',
                    'description' => 'Keep dispatchable drivers, vehicles, and fleet groupings ready for live work.',
                    'links'       => [
                        $this->link('Drivers', 'management.drivers', 'id-card', $counts['drivers'], 'Manage driver profiles, assignments, and schedules.'),
                        $this->link('Vehicles', 'management.vehicles', 'truck', $counts['vehicles'], 'Track vehicles, device links, equipment, and service context.'),
                        $this->link('Fleets', 'management.fleets', 'user-group', $counts['fleets'], 'Group drivers and vehicles by team, region, or service type.'),
                    ],
                ],
                [
                    'key'         => 'network',
                    'title'       => 'Operating Network',
                    'description' => 'Maintain the partners, contacts, and places used while creating and dispatching orders.',
                    'links'       => [
                        $this->link('Vendors', 'management.vendors', 'warehouse', $counts['vendors'], 'Service partners connected to operational work.'),
                        $this->link('Contacts', 'management.contacts', 'address-book', $counts['contacts'], 'Customer and contact records for order workflows.'),
                        $this->link('Places', 'management.places', 'location-dot', $counts['places'], 'Reusable locations, zones, and address context.'),
                    ],
                ],
                [
                    'key'         => 'exceptions',
                    'title'       => 'Fuel And Exceptions',
                    'description' => 'Review fuel activity and operational issues before they affect service quality.',
                    'links'       => [
                        $this->link('Fuel Reports', 'management.fuel-reports', 'gas-pump', $counts['fuel_reports'], 'Review reported fuel activity and costs.'),
                        $this->link('Fuel Transactions', 'management.fuel-transactions', 'credit-card', $counts['fuel_transactions'], 'Inspect transaction-level fueling data and matching context.'),
                        $this->link('Issues', 'management.issues', 'triangle-exclamation', $counts['issues'], 'Track incidents, service issues, and follow-up work.'),
                    ],
                ],
            ],
            'docs'     => [
                $this->doc('Drivers', 'id-card', 'fleet-ops/resources/drivers/overview', 'Drivers guide', 'Create driver profiles, app access, assignment context, and operating status.'),
                $this->doc('Vehicles', 'truck', 'fleet-ops/resources/vehicles/overview', 'Vehicles guide', 'Track fleet assets, assignment readiness, and vehicle operating details.'),
                $this->doc('Fleets', 'user-group', 'fleet-ops/resources/fleets/overview', 'Fleets guide', 'Group drivers and vehicles by team, region, or service coverage.'),
                $this->doc('Contacts', 'address-book', 'fleet-ops/resources/contacts/overview', 'Contacts guide', 'Manage people and organizations used across orders and operational records.'),
                $this->doc('Places', 'location-dot', 'fleet-ops/resources/places/overview', 'Places guide', 'Manage reusable pickup, dropoff, hub, and facility locations.'),
                $this->doc('Issues', 'triangle-exclamation', 'fleet-ops/resources/issues/overview', 'Issues guide', 'Track incidents, service exceptions, and operational follow-up.'),
            ],
        ]);
    }

    public function maintenance(Request $request)
    {
        $company = $this->companyUuid($request);
        $now     = Carbon::now();
        $next7   = Carbon::now()->addDays(7);

        $overdueSchedules        = $this->count(MaintenanceSchedule::query()->where('status', 'active')->whereNotNull('next_due_date')->where('next_due_date', '<', $now), $company);
        $upcomingSchedules       = $this->count(MaintenanceSchedule::query()->where('status', 'active')->whereBetween('next_due_date', [$now, $next7]), $company);
        $openWorkOrders          = $this->count(WorkOrder::query()->whereIn('status', ['open', 'in_progress']), $company);
        $overdueWorkOrders       = $this->count(WorkOrder::query()->whereNotIn('status', ['closed', 'canceled'])->whereNotNull('due_at')->where('due_at', '<', $now), $company);
        $openMaintenance         = $this->count(Maintenance::query()->whereNotIn('status', ['completed', 'canceled']), $company);
        $highPriorityMaintenance = $this->count(Maintenance::query()->whereNotIn('status', ['completed', 'canceled'])->whereIn('priority', ['high', 'urgent', 'critical']), $company);
        $lowStockParts           = $this->count(Part::query()->where('quantity_on_hand', '>', 0)->where('quantity_on_hand', '<=', 5), $company);
        $equipment               = $this->count(Equipment::query(), $company);

        return response()->json([
            'kpis'     => [
                $this->kpi('overdue_schedules', 'Overdue Schedules', $overdueSchedules, $overdueSchedules > 0 ? 'Recurring service needs attention.' : 'No recurring service is overdue.', $overdueSchedules > 0 ? 'rose' : 'green', 'calendar-xmark', 'maintenance.schedules'),
                $this->kpi('upcoming_schedules', 'Due This Week', $upcomingSchedules, 'Maintenance schedules due in the next 7 days.', 'blue', 'calendar-day', 'maintenance.schedules'),
                $this->kpi('open_work_orders', 'Open Work Orders', $openWorkOrders, $openWorkOrders > 0 ? 'Active work needs assignment or closure.' : 'No open work orders right now.', $openWorkOrders > 0 ? 'amber' : 'green', 'clipboard-list', 'maintenance.work-orders'),
                $this->kpi('low_stock_parts', 'Low Stock Parts', $lowStockParts, $lowStockParts > 0 ? 'Parts inventory may need replenishment.' : 'No low-stock parts detected.', $lowStockParts > 0 ? 'amber' : 'green', 'cog', 'maintenance.parts'),
            ],
            'actions'  => $this->maintenanceActions($overdueSchedules, $upcomingSchedules, $openWorkOrders, $overdueWorkOrders, $highPriorityMaintenance, $lowStockParts, $equipment),
            'sections' => [
                [
                    'key'         => 'planning',
                    'title'       => 'Planning',
                    'description' => 'Schedule recurring service before work turns urgent.',
                    'links'       => [
                        $this->link('Schedules', 'maintenance.schedules', 'calendar-alt', $upcomingSchedules + $overdueSchedules, 'Recurring maintenance intervals and service windows.'),
                        $this->link('Work Orders', 'maintenance.work-orders', 'clipboard-list', $openWorkOrders, 'Repair tasks, assignments, vendor work, and closure.'),
                    ],
                ],
                [
                    'key'         => 'records',
                    'title'       => 'Records',
                    'description' => 'Keep the maintenance history and serviceable asset catalog current.',
                    'links'       => [
                        $this->link('Maintenances', 'maintenance.maintenances', 'history', $openMaintenance, 'Maintenance records and service history by asset.'),
                        $this->link('Equipment', 'maintenance.equipment', 'trailer', $equipment, 'Assigned equipment and serviceable operational assets.'),
                        $this->link('Parts', 'maintenance.parts', 'cog', $lowStockParts, 'Parts inventory visibility for repair planning.'),
                    ],
                ],
            ],
            'docs'     => [
                $this->doc('Schedules', 'calendar-alt', 'fleet-ops/maintenance/schedules/overview', 'Maintenance schedules guide', 'Plan recurring service windows and convert due schedules into work orders.'),
                $this->doc('Work Orders', 'clipboard-list', 'fleet-ops/maintenance/work-orders/overview', 'Work orders guide', 'Coordinate assigned maintenance work, vendors, due dates, and completion.'),
                $this->doc('Equipment', 'trailer', 'fleet-ops/maintenance/equipment/overview', 'Equipment guide', 'Track serviceable equipment that participates in maintenance operations.'),
                $this->doc('Parts', 'cog', 'fleet-ops/maintenance/parts/overview', 'Parts guide', 'Manage parts inventory and restocking signals used by maintenance teams.'),
            ],
        ]);
    }

    protected function resourceActions(array $counts, int $fuelRecords): array
    {
        $actions = [];

        if ($counts['drivers'] === 0) {
            $actions[] = $this->action('add_drivers', 'Add driver profiles', 'Drivers are required before dispatch can assign live orders.', 'warning', 'id-card', 'management.drivers');
        }

        if ($counts['vehicles'] === 0) {
            $actions[] = $this->action('add_vehicles', 'Add vehicles', 'Vehicles make assignment, tracking, and service context usable.', 'warning', 'truck', 'management.vehicles');
        }

        if ($counts['drivers_without_vehicles'] > 0 && $counts['vehicles'] > 0) {
            $actions[] = $this->action('assign_vehicles_to_drivers', 'Assign vehicles to drivers', "{$counts['drivers_without_vehicles']} driver" . ($counts['drivers_without_vehicles'] === 1 ? ' does' : 's do') . ' not have an assigned vehicle.', 'warning', 'id-card', 'management.drivers', ['vehicle' => 'unassigned']);
        }

        if ($counts['vehicles_without_drivers'] > 0 && $counts['drivers'] > 0) {
            $actions[] = $this->action('assign_drivers_to_vehicles', 'Assign drivers to vehicles', "{$counts['vehicles_without_drivers']} vehicle" . ($counts['vehicles_without_drivers'] === 1 ? ' does' : 's do') . ' not have an assigned driver.', 'warning', 'truck', 'management.vehicles', ['driver' => 'unassigned']);
        }

        if ($counts['vehicles_without_devices'] > 0 && $counts['unattached_devices'] > 0) {
            $actions[] = $this->action('attach_devices_to_vehicles', 'Attach devices to vehicles', "{$counts['unattached_devices']} device" . ($counts['unattached_devices'] === 1 ? ' is' : 's are') . ' ready to attach to vehicles without telemetry hardware.', 'info', 'satellite-dish', 'connectivity.devices', ['attachment_state' => 'unattached']);
        }

        if ($counts['resource_issues'] > 0) {
            $actions[] = $this->action('review_resource_issues', 'Review vehicle and driver issues', "{$counts['resource_issues']} issue" . ($counts['resource_issues'] === 1 ? ' is' : 's are') . ' linked to drivers or vehicles.', 'warning', 'triangle-exclamation', 'management.issues', ['status' => 'open']);
        } elseif ($counts['issues'] > 0) {
            $actions[] = $this->action('review_issues', 'Review open issues', "{$counts['issues']} issue" . ($counts['issues'] === 1 ? ' needs' : 's need') . ' follow-up.', 'warning', 'triangle-exclamation', 'management.issues', ['status' => 'open']);
        }

        if (($counts['overdue_vehicle_schedules'] + $counts['upcoming_vehicle_schedules']) > 0) {
            $serviceCount = $counts['overdue_vehicle_schedules'] + $counts['upcoming_vehicle_schedules'];
            $actions[]    = $this->action('prepare_vehicle_maintenance', 'Prepare upcoming maintenance', "{$serviceCount} vehicle schedule" . ($serviceCount === 1 ? ' needs' : 's need') . ' maintenance planning.', $counts['overdue_vehicle_schedules'] > 0 ? 'warning' : 'info', 'calendar-day', 'maintenance.schedules', ['status' => 'active']);
        }

        if ($counts['overdue_resource_work_orders'] > 0) {
            $actions[] = $this->action('close_overdue_work_orders', 'Close overdue work orders', "{$counts['overdue_resource_work_orders']} resource work order" . ($counts['overdue_resource_work_orders'] === 1 ? ' is' : 's are') . ' past due.', 'warning', 'clipboard-list', 'maintenance.work-orders', ['status' => 'open']);
        } elseif ($counts['open_resource_work_orders'] > 0) {
            $actions[] = $this->action('review_open_work_orders', 'Review open work orders', "{$counts['open_resource_work_orders']} resource work order" . ($counts['open_resource_work_orders'] === 1 ? ' is' : 's are') . ' open for vehicles or equipment.', 'info', 'clipboard-list', 'maintenance.work-orders', ['status' => 'open']);
        }

        if ($counts['low_stock_parts'] > 0) {
            $actions[] = $this->action('replenish_low_stock_parts', 'Replenish low-stock parts', "{$counts['low_stock_parts']} stocked part" . ($counts['low_stock_parts'] === 1 ? ' is' : 's are') . ' at or below the default threshold.', 'warning', 'cog', 'maintenance.parts', ['status' => 'in_stock']);
        }

        if ($counts['unmatched_fuel_transactions'] > 0) {
            $actions[] = $this->action('review_unmatched_fuel_transactions', 'Review unmatched fuel transactions', "{$counts['unmatched_fuel_transactions']} fuel transaction" . ($counts['unmatched_fuel_transactions'] === 1 ? ' needs' : 's need') . ' resource matching.', 'warning', 'gas-pump', 'management.fuel-transactions', ['sync_status' => 'unmatched']);
        }

        if ($counts['fleets'] === 0 && $counts['drivers'] > 0 && $counts['vehicles'] > 0) {
            $actions[] = $this->action('create_fleets', 'Create fleet groups', 'Group active drivers and vehicles by team, region, or service type.', 'info', 'user-group', 'management.fleets');
        }

        if ($counts['places'] === 0) {
            $actions[] = $this->action('add_operating_places', 'Add operating places', 'Places keep recurring pickup, dropoff, facility, and service locations ready.', 'info', 'location-dot', 'management.places');
        }

        if ($counts['vendors'] === 0) {
            $actions[] = $this->action('add_service_vendors', 'Add vendors for service work', 'Vendors connect maintenance, service work, and operating partners to resources.', 'info', 'warehouse', 'management.vendors');
        }

        if ($counts['contacts'] === 0) {
            $actions[] = $this->action('add_contacts', 'Add operating contacts', 'Contacts connect customers, partners, and service people to order workflows.', 'info', 'address-book', 'management.contacts');
        }

        if ($fuelRecords === 0) {
            $actions[] = $this->action('review_fuel', 'Open fuel records', 'Fuel activity will appear here once reports or transactions are captured.', 'info', 'gas-pump', 'management.fuel-reports');
        }

        return $actions ? array_slice($actions, 0, 6) : [$this->action('ready', 'Core resources look ready', 'Drivers, vehicles, issues, and supporting records are in a healthy operating posture.', 'success', 'check-circle', null)];
    }

    protected function maintenanceActions(int $overdueSchedules, int $upcomingSchedules, int $openWorkOrders, int $overdueWorkOrders, int $highPriorityMaintenance, int $lowStockParts, int $equipment): array
    {
        $actions = [];

        if ($overdueSchedules > 0) {
            $actions[] = $this->action('overdue_schedules', 'Review overdue schedules', "{$overdueSchedules} recurring service schedule" . ($overdueSchedules === 1 ? ' is' : 's are') . ' overdue.', 'warning', 'calendar-xmark', 'maintenance.schedules');
        }

        if ($overdueWorkOrders > 0) {
            $actions[] = $this->action('overdue_work_orders', 'Close overdue work orders', "{$overdueWorkOrders} work order" . ($overdueWorkOrders === 1 ? ' is' : 's are') . ' past due.', 'warning', 'clipboard-list', 'maintenance.work-orders');
        }

        if ($highPriorityMaintenance > 0) {
            $actions[] = $this->action('high_priority_maintenance', 'Prioritize critical maintenance', "{$highPriorityMaintenance} high-priority maintenance record" . ($highPriorityMaintenance === 1 ? ' needs' : 's need') . ' review.', 'warning', 'wrench', 'maintenance.maintenances');
        }

        if ($upcomingSchedules > 0) {
            $actions[] = $this->action('upcoming_service', 'Prepare upcoming service', "{$upcomingSchedules} schedule" . ($upcomingSchedules === 1 ? ' is' : 's are') . ' due in the next 7 days.', 'info', 'calendar-day', 'maintenance.schedules');
        }

        if ($openWorkOrders === 0) {
            $actions[] = $this->action('no_open_work_orders', 'No open work orders', 'Create work orders when scheduled service or asset issues need assignment.', 'success', 'check-circle', 'maintenance.work-orders');
        }

        if ($lowStockParts > 0) {
            $actions[] = $this->action('low_stock_parts', 'Replenish low-stock parts', "{$lowStockParts} stocked part" . ($lowStockParts === 1 ? ' is' : 's are') . ' at or below the default threshold.', 'warning', 'cog', 'maintenance.parts');
        }

        if ($equipment === 0) {
            $actions[] = $this->action('add_equipment', 'Add serviceable equipment', 'Equipment records make maintenance planning more complete.', 'info', 'trailer', 'maintenance.equipment');
        }

        return array_slice($actions, 0, 5);
    }

    protected function count(Builder $query, ?string $companyUuid): int
    {
        return (int) $query->when($companyUuid, fn ($query) => $query->where('company_uuid', $companyUuid))->count();
    }

    protected function companyUuid(Request $request): ?string
    {
        return session('company') ?? $request->user()?->company_uuid ?? $request->user()?->company?->uuid;
    }

    protected function kpi(string $key, string $label, int $value, string $caption, string $tone, string $icon, string $route): array
    {
        return compact('key', 'label', 'value', 'caption', 'tone', 'icon', 'route');
    }

    protected function action(string $key, string $label, string $description, string $tone, string $icon, ?string $route, array $query = []): array
    {
        $query = (object) $query;

        return compact('key', 'label', 'description', 'tone', 'icon', 'route', 'query');
    }

    protected function link(string $label, string $route, string $icon, int $count, string $description): array
    {
        return compact('label', 'route', 'icon', 'count', 'description');
    }

    protected function doc(string $label, string $icon, string $slug, string $title, string $description = ''): array
    {
        return compact('label', 'icon', 'slug', 'title', 'description');
    }
}
