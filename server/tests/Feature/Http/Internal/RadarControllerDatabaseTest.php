<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\RadarController;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\Part;
use Fleetbase\FleetOps\Models\Trailer;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Models\Alert;
use Fleetbase\Models\User;
use Illuminate\Config\Repository;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

if (!function_exists('report')) {
    // The controller reports a failing source and carries on; record what it
    // reported so a test can see the failure went somewhere.
    function report($exception)
    {
        $GLOBALS['fleetOpsRadarReported'][] = $exception instanceof Throwable ? $exception->getMessage() : (string) $exception;
    }
}

/**
 * The real controller over an in-memory database: only the clock is fixed.
 */
class FleetOpsRadarDatabaseController extends RadarController
{
    protected function now(): Carbon
    {
        return Carbon::parse('2026-09-15 08:35:00', 'UTC');
    }
}

/**
 * Every table Radar's loaders read, as nullable strings, with MySQL's
 * spatial functions standing in so a driver's location reads back as a point.
 */
function fleetOpsRadarDatabase(): SQLiteConnection
{
    $pdo           = new PDO('sqlite::memory:');
    $asStoredPoint = function ($wkt, $srid = 0, $axisOrder = null) {
        if (!preg_match('/POINT\s*\(\s*(-?[\d.]+)\s+(-?[\d.]+)\s*\)/i', (string) $wkt, $pair)) {
            return $wkt;
        }

        return pack('V', (int) $srid) . pack('C', 1) . pack('V', 1) . pack('d', (float) $pair[1]) . pack('d', (float) $pair[2]);
    };
    $pdo->sqliteCreateFunction('ST_PointFromText', $asStoredPoint);
    $pdo->sqliteCreateFunction('ST_GeomFromText', $asStoredPoint);

    $connection = new SQLiteConnection($pdo);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    EloquentModel::setEventDispatcher(new Dispatcher());
    EloquentModel::clearBootedModels();

    $config = new Repository([
        'activitylog' => ['enabled' => false, 'default_auth_driver' => null, 'default_log_name' => 'default'],
        'api'         => ['cache' => ['enabled' => false]],
        'filesystems' => ['default' => 'local'],
    ]);
    app()->instance('config', $config);
    app()->instance(Illuminate\Contracts\Config\Repository::class, $config);
    app()->instance(Spatie\Activitylog\CauserResolver::class, new class extends Spatie\Activitylog\CauserResolver {
        public function __construct()
        {
        }

        public function resolve(EloquentModel|int|string|null $subject = null): ?EloquentModel
        {
            return null;
        }
    });
    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $connection)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->connection;
        }

        public function __call($method, $arguments)
        {
            return $this->connection->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');
    app()->instance('db.schema', $connection->getSchemaBuilder());
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    app()->instance('request', Request::create('/'));

    $common = ['uuid', 'public_id', '_key', 'company_uuid', 'meta', 'slug', 'internal_id'];
    $tables = [
        'companies'                  => ['name', 'owner_uuid'],
        'users'                      => ['name', 'email', 'phone', 'avatar_uuid', 'type', 'status', 'timezone'],
        'company_users'              => ['user_uuid', 'role_uuid', 'status'],
        'files'                      => ['uploader_uuid', 'subject_uuid', 'subject_type', 'path', 'disk', 'bucket', 'type', 'content_type', 'original_filename', 'file_size', 'caption', 'folder', 'etag'],
        'settings'                   => ['key', 'value'],
        'alerts'                     => ['category_uuid', 'acknowledged_by_uuid', 'resolved_by_uuid', 'snoozed_by_uuid', 'assigned_to_uuid', 'type', 'severity', 'status', 'subject_type', 'subject_uuid', 'message', 'rule', 'context', 'triggered_at', 'acknowledged_at', 'resolved_at', 'snoozed_until', 'planned_at'],
        'maintenance_schedules'      => ['subject_type', 'subject_uuid', 'name', 'type', 'status', 'next_due_date', 'next_due_odometer', 'default_priority', 'default_assignee_type', 'default_assignee_uuid', 'instructions', 'reminder_offsets', 'interval_method', 'interval_type', 'interval_value', 'interval_unit'],
        'work_orders'                => ['schedule_uuid', 'category_uuid', 'code', 'subject', 'category', 'status', 'priority', 'target_type', 'target_uuid', 'assignee_type', 'assignee_uuid', 'opened_at', 'due_at', 'closed_at', 'instructions', 'checklist', 'currency', 'estimated_cost', 'approved_budget', 'actual_cost', 'created_by_uuid', 'updated_by_uuid'],
        'maintenances'               => ['maintainable_type', 'maintainable_uuid', 'work_order_uuid', 'status', 'scheduled_at', 'completed_at', 'type', 'priority'],
        'issues'                     => ['reported_by_uuid', 'assigned_to_uuid', 'vehicle_uuid', 'driver_uuid', 'order_uuid', 'issue_id', 'location', 'category', 'type', 'report', 'title', 'tags', 'priority', 'resolved_at', 'status'],
        'drivers'                    => ['user_uuid', 'vehicle_uuid', 'vendor_uuid', 'current_job_uuid', 'photo_uuid', 'status', 'current_status', 'online', 'location', 'license_expiry', 'drivers_license_number', 'country', 'city'],
        'vehicles'                   => ['vendor_uuid', 'photo_uuid', 'category_uuid', 'warranty_uuid', 'telematic_uuid', 'name', 'make', 'model', 'year', 'trim', 'plate_number', 'vin', 'call_sign', 'status', 'online', 'location', 'fuel_card_number', 'lease_expires_at', 'currency', 'measurement_system'],
        'devices'                    => ['telematic_uuid', 'warranty_uuid', 'photo_uuid', 'attachable_uuid', 'attachable_type', 'type', 'device_id', 'name', 'status', 'online', 'last_online_at', 'last_position', 'data', 'options'],
        'assets'                     => ['asset_class', 'category_uuid', 'vendor_uuid', 'warranty_uuid', 'photo_uuid', 'telematic_uuid', 'assigned_to_uuid', 'assigned_to_type', 'operator_uuid', 'operator_type', 'name', 'code', 'type', 'make', 'model', 'year', 'plate_number', 'vin', 'status', 'online', 'last_online_at', 'location', 'lease_expires_at', 'purchased_at', 'specs', 'attributes', 'telematics', 'notes'],
        'asset_connections'          => ['asset_uuid', 'connected_to_uuid', 'connected_to_type', 'status', 'connected_at', 'disconnected_at'],
        'equipments'                 => ['name', 'code', 'type', 'status', 'equipable_type', 'equipable_uuid', 'photo_uuid', 'warranty_uuid', 'serial_number', 'manufacturer', 'model'],
        'parts'                      => ['vendor_uuid', 'warranty_uuid', 'photo_uuid', 'asset_type', 'asset_uuid', 'sku', 'name', 'quantity_on_hand', 'reorder_point', 'reorder_quantity', 'unit_cost', 'currency', 'type', 'status', 'specs'],
        'fuel_provider_transactions' => ['fuel_provider_connection_uuid', 'fuel_report_uuid', 'vehicle_uuid', 'driver_uuid', 'order_uuid', 'provider', 'provider_transaction_id', 'vehicle_card_id', 'plate_number', 'vin', 'station_name', 'station_latitude', 'station_longitude', 'transaction_at', 'volume', 'metric_unit', 'amount', 'currency', 'sync_status', 'matched_at'],
        'inspection_forms'           => ['name', 'description', 'type', 'status', 'subject_type', 'subject_uuid', 'items', 'settings', 'published_at', 'created_by_uuid', 'updated_by_uuid'],
        'inspection_submissions'     => ['inspection_form_uuid', 'vehicle_uuid', 'driver_uuid', 'submitted_by_uuid', 'issue_uuid', 'work_order_uuid', 'type', 'status', 'result', 'source', 'total_items', 'failed_items', 'started_at', 'submitted_at', 'resolved_at', 'location', 'signature', 'attachments', 'created_by_uuid', 'updated_by_uuid'],
        'inspection_item_results'    => ['inspection_submission_uuid', 'issue_uuid', 'work_order_uuid', 'item_key', 'label', 'category', 'status', 'severity', 'passed', 'comments', 'photos', 'created_by_uuid', 'updated_by_uuid'],
        'inspection_links'           => ['inspection_form_uuid', 'driver_uuid', 'vehicle_uuid', 'assignee_uuid', 'created_by_uuid', 'token_hash', 'token', 'pin_hash', 'pin', 'pin_attempts', 'status', 'single_use', 'expires_at', 'last_viewed_at', 'used_at'],
        'schedule_items'             => ['schedule_uuid', 'template_uuid', 'assignee_uuid', 'assignee_type', 'resource_uuid', 'resource_type', 'start_at', 'end_at', 'duration', 'break_start_at', 'break_end_at', 'status', 'is_exception', 'exception_for_date'],
        'orders'                     => ['driver_assigned_uuid', 'vehicle_assigned_uuid', 'payload_uuid', 'tracking_number_uuid', 'order_config_uuid', 'status', 'type', 'scheduled_at', 'dispatched_at', 'started_at', 'time_window_start', 'time_window_end'],
        'payloads'                   => ['pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid'],
        'entities'                   => ['payload_uuid', 'destination_uuid', 'customer_uuid', 'customer_type', 'photo_uuid', 'name', 'type'],
        'waypoints'                  => ['payload_uuid', 'place_uuid', 'tracking_number_uuid', 'customer_uuid', 'customer_type', 'order', 'type'],
        'places'                     => ['owner_uuid', 'owner_type', 'name', 'street1', 'street2', 'city', 'country', 'location'],
        'activity_log'               => ['log_name', 'description', 'subject_type', 'subject_id', 'causer_type', 'causer_id', 'properties', 'event', 'batch_uuid'],
    ];

    $schema = $connection->getSchemaBuilder();
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($common, $columns, $table) {
            $blueprint->increments('id');
            foreach (array_unique(array_merge($table === 'settings' ? [] : $common, $columns)) as $column) {
                $blueprint->string($column)->nullable();
            }
            if ($table !== 'settings') {
                $blueprint->timestamps();
                $blueprint->timestamp('deleted_at')->nullable();
            }
        });
    }

    $point = fn (float $lat, float $lng) => $asStoredPoint("POINT({$lng} {$lat})");
    // Multi-row inserts need the same columns on every row.
    $db = function (string $table, array $rows) use ($connection) {
        $rows    = array_map(fn ($row) => $row + ['company_uuid' => 'company-radar'], $rows);
        $columns = array_fill_keys(array_merge(...array_map('array_keys', $rows)), null);
        $connection->table($table)->insert(array_map(fn ($row) => array_merge($columns, $row), $rows));
    };

    $connection->table('companies')->insert(['uuid' => 'company-radar', 'public_id' => 'company_radar', 'name' => 'Radar Co']);
    $db('users', [
        ['uuid' => 'user-ada', 'public_id' => 'user_ada', 'name' => 'Ada Ops', 'type' => 'user'],
        ['uuid' => 'user-ortega', 'public_id' => 'user_ortega', 'name' => 'Luis Ortega', 'phone' => '+15550001', 'type' => 'driver'],
        ['uuid' => 'user-alves', 'public_id' => 'user_alves', 'name' => 'Tomas Alves', 'type' => 'driver'],
        ['uuid' => 'user-nair', 'public_id' => 'user_nair', 'name' => 'Priya Nair', 'type' => 'driver'],
    ]);
    $connection->table('users')->insert(['uuid' => 'user-guest', 'public_id' => 'user_guest', 'company_uuid' => 'company-other', 'name' => 'Guest Member', 'type' => 'user']);
    $connection->table('users')->insert(['uuid' => 'user-stranger', 'public_id' => 'user_stranger', 'company_uuid' => 'company-other', 'name' => 'Stranger', 'type' => 'user']);
    $connection->table('company_users')->insert(['uuid' => 'cu-guest', 'company_uuid' => 'company-radar', 'user_uuid' => 'user-guest', 'status' => 'active']);

    $db('vehicles', [
        ['uuid' => 'vehicle-118', 'public_id' => 'vehicle_118', 'name' => 'TRK-118', 'status' => 'available', 'plate_number' => 'PLT-118', 'fuel_card_number' => '4471'],
        ['uuid' => 'vehicle-042', 'public_id' => 'vehicle_042', 'name' => 'VAN-042', 'status' => 'available', 'lease_expires_at' => '2026-09-30 00:00:00'],
    ]);
    $db('drivers', [
        ['uuid' => 'driver-ortega', 'public_id' => 'driver_ortega', 'user_uuid' => 'user-ortega', 'vehicle_uuid' => 'vehicle-118', 'status' => 'active', 'online' => '1', 'location' => $point(40.70, -74.00)],
        ['uuid' => 'driver-alves', 'public_id' => 'driver_alves', 'user_uuid' => 'user-alves', 'vehicle_uuid' => 'vehicle-777', 'status' => 'active', 'online' => '1', 'location' => $point(40.71, -74.00)],
        ['uuid' => 'driver-nair', 'public_id' => 'driver_nair', 'user_uuid' => 'user-nair', 'status' => 'active', 'online' => '0', 'license_expiry' => '2026-10-01', 'drivers_license_number' => 'D-9'],
    ]);
    $db('schedule_items', [
        ['uuid' => 'shift-ortega', 'public_id' => 'shift_ortega', 'assignee_uuid' => 'driver-ortega', 'assignee_type' => Driver::class, 'start_at' => '2026-09-15 01:15:00', 'end_at' => '2026-09-15 09:15:00', 'status' => 'in_progress'],
        ['uuid' => 'shift-alves', 'public_id' => 'shift_alves', 'assignee_uuid' => 'driver-alves', 'assignee_type' => Driver::class, 'start_at' => '2026-09-15 06:00:00', 'end_at' => '2026-09-15 21:00:00', 'status' => 'in_progress'],
    ]);
    $db('places', [['uuid' => 'place-bay', 'public_id' => 'place_bay', 'name' => 'Bay Ridge']]);
    $db('payloads', [['uuid' => 'payload-1', 'public_id' => 'payload_1', 'dropoff_uuid' => 'place-bay']]);
    $db('orders', [
        ['uuid' => 'order-1', 'public_id' => 'order_1', 'driver_assigned_uuid' => 'driver-ortega', 'payload_uuid' => 'payload-1', 'status' => 'dispatched', 'scheduled_at' => '2026-09-15 09:40:00'],
    ]);
    $db('assets', [
        ['uuid' => 'trailer-019', 'public_id' => 'trailer_019', 'asset_class' => 'trailer', 'name' => 'TRL-019', 'status' => 'available', 'lease_expires_at' => '2026-09-20 00:00:00'],
    ]);
    $db('devices', [['uuid' => 'device-477', 'public_id' => 'device_477', 'name' => 'GPS-477', 'device_id' => '477', 'status' => 'active']]);
    $db('equipments', [['uuid' => 'equipment-1', 'public_id' => 'equipment_1', 'name' => 'Reefer unit']]);
    $db('parts', [['uuid' => 'part-pads', 'public_id' => 'part_pads', 'name' => 'Brake pads', 'sku' => 'PN 8842', 'quantity_on_hand' => '2', 'reorder_point' => '6']]);
    $db('maintenance_schedules', [
        ['uuid' => 'schedule-oil', 'public_id' => 'schedule_oil', 'subject_type' => Vehicle::class, 'subject_uuid' => 'vehicle-118', 'name' => 'Oil change', 'type' => 'oil_change', 'status' => 'active', 'next_due_date' => '2026-09-12 08:00:00'],
        ['uuid' => 'schedule-trailer', 'public_id' => 'schedule_trailer', 'subject_type' => Trailer::class, 'subject_uuid' => 'trailer-019', 'name' => 'Trailer service', 'type' => 'inspection', 'status' => 'active', 'next_due_date' => '2026-09-16 08:00:00'],
        ['uuid' => 'schedule-device', 'public_id' => 'schedule_device', 'subject_type' => Device::class, 'subject_uuid' => 'device-477', 'name' => 'Firmware check', 'type' => 'other', 'status' => 'active', 'next_due_date' => '2026-09-17 08:00:00'],
        ['uuid' => 'schedule-part', 'public_id' => 'schedule_part', 'subject_type' => Part::class, 'subject_uuid' => 'part-pads', 'name' => 'Stock count', 'type' => 'other', 'status' => 'active', 'next_due_date' => '2026-09-18 08:00:00'],
        ['uuid' => 'schedule-equipment', 'public_id' => 'schedule_equipment', 'subject_type' => Equipment::class, 'subject_uuid' => 'equipment-1', 'name' => 'Reefer service', 'type' => 'other', 'status' => 'active', 'next_due_date' => '2026-09-19 08:00:00'],
    ]);
    $db('work_orders', [
        ['uuid' => 'wo-2041', 'public_id' => 'work_order_2041', 'code' => 'WO-2041', 'subject' => 'brake pads', 'status' => 'in_progress', 'priority' => 'high', 'target_type' => Vehicle::class, 'target_uuid' => 'vehicle-118', 'due_at' => '2026-09-13 09:00:00'],
        ['uuid' => 'wo-oil', 'public_id' => 'work_order_oil', 'code' => 'WO-9', 'subject' => 'oil', 'status' => 'open', 'schedule_uuid' => 'schedule-trailer', 'due_at' => '2026-09-30 09:00:00'],
    ]);
    $db('issues', [
        ['uuid' => 'issue-1', 'public_id' => 'issue_1', 'title' => 'Check engine light', 'priority' => 'high', 'status' => 'pending', 'vehicle_uuid' => 'vehicle-118', 'driver_uuid' => 'driver-ortega'],
    ]);
    $db('fuel_provider_transactions', [
        ['uuid' => 'fuel-1', 'public_id' => 'fuel_provider_transaction_1', 'amount' => '21240', 'currency' => 'USD', 'station_name' => 'Pilot #331', 'vehicle_card_id' => '4471', 'sync_status' => 'unmatched', 'transaction_at' => '2026-09-14 08:04:00'],
    ]);
    $db('inspection_forms', [['uuid' => 'form-1', 'public_id' => 'inspection_form_1', 'name' => 'Pre-trip DVIR', 'type' => 'dvir', 'status' => 'published']]);
    $db('inspection_submissions', [
        ['uuid' => 'sub-failed', 'public_id' => 'inspection_submission_failed', 'inspection_form_uuid' => 'form-1', 'vehicle_uuid' => 'vehicle-042', 'driver_uuid' => 'driver-nair', 'type' => 'dvir', 'status' => 'submitted', 'result' => 'failed', 'total_items' => '3', 'failed_items' => '2', 'submitted_at' => '2026-09-15 07:00:00'],
        ['uuid' => 'sub-plain', 'public_id' => 'inspection_submission_plain', 'inspection_form_uuid' => 'form-1', 'vehicle_uuid' => 'vehicle-118', 'type' => 'dvir', 'status' => 'submitted', 'result' => 'failed', 'total_items' => '1', 'failed_items' => '1', 'submitted_at' => '2026-09-15 07:00:00'],
        ['uuid' => 'sub-draft', 'public_id' => 'inspection_submission_draft', 'inspection_form_uuid' => 'form-1', 'type' => 'dvir', 'status' => 'draft', 'total_items' => '0', 'failed_items' => '0', 'started_at' => '2026-09-13 07:00:00'],
    ]);
    $db('inspection_item_results', [
        ['uuid' => 'res-1', 'inspection_submission_uuid' => 'sub-failed', 'label' => 'Brakes', 'severity' => 'Critical', 'status' => 'failed', 'passed' => '0'],
        ['uuid' => 'res-2', 'inspection_submission_uuid' => 'sub-failed', 'label' => 'Wipers', 'severity' => 'low', 'status' => 'failed', 'passed' => '0'],
    ]);
    $db('inspection_links', [
        ['uuid' => 'link-1', 'public_id' => 'inspection_link_1', 'inspection_form_uuid' => 'form-1', 'driver_uuid' => 'driver-nair', 'status' => 'active', 'expires_at' => '2026-09-15 20:00:00'],
    ]);

    session(['company' => 'company-radar', 'user' => 'user-ada']);
    $GLOBALS['fleetOpsRadarReported'] = [];

    return $connection;
}

function fleetOpsRadarDatabaseRequest(string $method = 'GET', array $parameters = [], ?string $userUuid = 'user-ada'): Request
{
    $request = Request::create('/int/v1/fleet-ops/radar', $method, $parameters);
    $request->setUserResolver(fn () => $userUuid ? User::query()->where('uuid', $userUuid)->first() : null);

    return $request;
}

afterEach(fn () => Carbon::setTestNow());

test('items, briefing and agenda load every source from the database', function () {
    fleetOpsRadarDatabase();
    Carbon::setTestNow(Carbon::parse('2026-09-15 08:35:00', 'UTC'));
    $controller = new FleetOpsRadarDatabaseController();

    $payload = $controller->items(fleetOpsRadarDatabaseRequest())->getData(true);
    $keys    = array_column($payload['items'], 'key');
    $byKey   = array_column($payload['items'], null, 'key');

    expect($payload['sources'])->toBe([])
        ->and($keys)->toContain(
            'maintenance_overdue:schedule_oil',
            'inspection_due:schedule_trailer',
            'maintenance_due_soon:schedule_device',
            'maintenance_due_soon:schedule_part',
            'maintenance_due_soon:schedule_equipment',
            'work_order_overdue:work_order_2041',
            'issue_open:issue_1',
            'shift_handover:driver_ortega',
            'license_expiring:driver_nair',
            'driver_without_vehicle:driver_nair',
            'vehicle_without_driver:vehicle_042',
            'lease_expiring:vehicle_042',
            'lease_expiring:trailer_019',
            'device_unattached:device_477',
            'part_low_stock:part_pads',
            'fuel_unmatched:fuel_provider_transaction_1',
            'inspection_failed:inspection_submission_failed',
            'inspection_failed:inspection_submission_plain',
            'inspection_draft:inspection_submission_draft',
            'inspection_link_pending:inspection_link_1',
        )
        ->and($byKey['maintenance_overdue:schedule_oil']['subject'])->toMatchArray(['type' => 'vehicle', 'label' => 'TRK-118'])
        ->and($byKey['inspection_due:schedule_trailer']['subject']['type'])->toBe('trailer')
        ->and($byKey['maintenance_due_soon:schedule_device']['subject']['type'])->toBe('device')
        ->and($byKey['maintenance_due_soon:schedule_part']['subject']['type'])->toBe('part')
        ->and($byKey['maintenance_due_soon:schedule_equipment']['subject']['type'])->toBe('equipment')
        ->and($byKey['issue_open:issue_1']['subject']['label'])->toBe('TRK-118')
        ->and($byKey['shift_handover:driver_ortega']['subject'])->toMatchArray(['label' => 'Luis Ortega', 'phone' => '+15550001'])
        ->and($byKey['fuel_unmatched:fuel_provider_transaction_1']['source']['suggested_vehicle']['public_id'])->toBe('vehicle_118')
        ->and($byKey['inspection_failed:inspection_submission_failed']['severity'])->toBe('critical')
        ->and(array_column($byKey['inspection_failed:inspection_submission_failed']['details']['failed_items'], 'label'))->toBe(['Brakes', 'Wipers'])
        ->and($byKey['inspection_failed:inspection_submission_plain']['severity'])->toBe('warning')
        ->and($byKey['inspection_link_pending:inspection_link_1']['record']['model'])->toBe('inspection_form_1');

    $brief = $controller->briefing(fleetOpsRadarDatabaseRequest())->getData(true);
    expect($brief['score']['delta'])->toBeNull()
        ->and($brief['sources'])->toBe([]);

    // The score was remembered, so a second reading has something to compare.
    $again = $controller->briefing(fleetOpsRadarDatabaseRequest())->getData(true);
    expect($again['score']['value'])->toBe($brief['score']['value']);

    $agenda = $controller->agenda(fleetOpsRadarDatabaseRequest('GET', ['window' => '24h']))->getData(true);
    expect($agenda['handovers'][0]['orders'][0])->toMatchArray(['public_id' => 'order_1', 'destination' => 'Bay Ridge'])
        ->and($agenda['handovers'][0]['suggested']['driver']['label'])->toBe('Tomas Alves')
        ->and($agenda['handovers'][0]['suggested']['distance_km'])->toBe(1.1);

    $card = $controller->handoverSuggest(fleetOpsRadarDatabaseRequest(), 'shift_handover:driver_ortega')->getData(true);
    expect($card['handover']['key'])->toBe('shift_handover:driver_ortega');
});

test('state actions write alert rows, resolve users by uuid and check company membership', function () {
    fleetOpsRadarDatabase();
    Carbon::setTestNow(Carbon::parse('2026-09-15 08:35:00', 'UTC'));
    $controller = new FleetOpsRadarDatabaseController();
    $key        = 'issue_open:issue_1';

    $acknowledged = $controller->acknowledge(fleetOpsRadarDatabaseRequest('POST'), $key)->getData(true);
    expect($acknowledged['state'])->toMatchArray(['status' => 'acknowledged', 'acknowledged_by_name' => 'Ada Ops'])
        ->and(Alert::query()->count())->toBe(1)
        ->and(Alert::query()->first()->public_id)->toStartWith('alert_');

    $snoozed = $controller->snooze(fleetOpsRadarDatabaseRequest('POST', ['minutes' => 60]), $key)->getData(true);
    expect($snoozed['state'])->toMatchArray(['status' => 'snoozed', 'snoozed_by_name' => 'Ada Ops']);

    $woken = $controller->wake(fleetOpsRadarDatabaseRequest('POST'), $key)->getData(true);
    expect($woken['state']['snoozed_until'])->toBeNull();

    // A member through company_users, a user of the company, and a stranger.
    $guest = $controller->assign(fleetOpsRadarDatabaseRequest('POST', ['user' => 'user_guest']), $key)->getData(true);
    expect($guest['state']['assigned_to'])->toMatchArray(['uuid' => 'user-guest', 'name' => 'Guest Member', 'initials' => 'GM']);

    $own = $controller->assign(fleetOpsRadarDatabaseRequest('POST', ['user' => 'user-ada']), $key)->getData(true);
    expect($own['state']['assigned_to']['name'])->toBe('Ada Ops');

    expect($controller->assign(fleetOpsRadarDatabaseRequest('POST', ['user' => 'user_stranger']), $key)->getStatusCode())->toBe(422)
        ->and($controller->assign(fleetOpsRadarDatabaseRequest('POST', ['user' => 'user_nobody']), $key)->getStatusCode())->toBe(422);

    $bulk = $controller->bulk(fleetOpsRadarDatabaseRequest('POST', ['keys' => [$key], 'action' => 'plan', 'planned_at' => '2026-09-15 14:00:00']))->getData(true);
    expect($bulk['results'][0]['state']['planned_at'])->toBe('2026-09-15T14:00:00+00:00');

    // Without a signed-in user the action still lands, unattributed.
    $anonymous = $controller->acknowledge(fleetOpsRadarDatabaseRequest('POST', [], null), 'part_low_stock:part_pads')->getData(true);
    expect($anonymous['state']['acknowledged_by_name'])->toBeNull();

    $notice    = $controller->storeNotice(fleetOpsRadarDatabaseRequest('POST', ['message' => 'Yard closed Saturday', 'severity' => 'warning']));
    $noticeKey = $notice->getData(true)['item']['key'];
    $publicId  = substr($noticeKey, strlen('notice:'));

    $resolved = $controller->resolve(fleetOpsRadarDatabaseRequest('POST', ['resolution' => 'Done']), $noticeKey)->getData(true);
    expect($resolved['state'])->toMatchArray(['status' => 'resolved', 'resolved_by_name' => 'Ada Ops', 'resolution' => 'Done']);

    expect($controller->destroyNotice(fleetOpsRadarDatabaseRequest('DELETE'), $publicId)->getData(true))->toBe(['deleted' => true])
        ->and($controller->destroyNotice(fleetOpsRadarDatabaseRequest('DELETE'), 'alert_nope')->getStatusCode())->toBe(404);

    $resolvedTab = $controller->items(fleetOpsRadarDatabaseRequest('GET', ['status' => 'resolved']))->getData(true);
    expect($resolvedTab['items'])->toBe([], 'a deleted notice leaves the resolved tab too');
});

test('extending a shift finds it among the company drivers and moves its end', function () {
    fleetOpsRadarDatabase();
    Carbon::setTestNow(Carbon::parse('2026-09-15 08:35:00', 'UTC'));
    $controller = new FleetOpsRadarDatabaseController();

    $payload = $controller->extendShift(fleetOpsRadarDatabaseRequest('POST', ['minutes' => 30]), 'shift_ortega')->getData(true);
    expect($payload['shift']['end_at'])->toBe('2026-09-15T09:45:00+00:00');

    expect($controller->extendShift(fleetOpsRadarDatabaseRequest('POST', ['minutes' => 30]), 'shift_nope')->getStatusCode())->toBe(404);

    // A company with no drivers has no shifts to extend.
    session(['company' => 'company-empty']);
    expect($controller->extendShift(fleetOpsRadarDatabaseRequest('POST', ['minutes' => 30]), 'shift_ortega')->getStatusCode())->toBe(404);
});

test('a failing source, state table or settings table degrades the page instead of failing it', function () {
    $connection = fleetOpsRadarDatabase();
    Carbon::setTestNow(Carbon::parse('2026-09-15 08:35:00', 'UTC'));
    $controller = new FleetOpsRadarDatabaseController();
    $schema     = $connection->getSchemaBuilder();

    // Handover orders that cannot load leave the card without orders.
    $schema->drop('payloads');
    $agenda = $controller->agenda(fleetOpsRadarDatabaseRequest())->getData(true);
    expect($agenda['handovers'][0]['orders'])->toBe([]);

    $schema->drop('inspection_links');
    $schema->drop('settings');
    $brief = $controller->briefing(fleetOpsRadarDatabaseRequest())->getData(true);
    expect(array_keys($brief['sources']))->toBe(['inspectionLinks'])
        ->and($brief['score']['delta'])->toBeNull()
        ->and($GLOBALS['fleetOpsRadarReported'])->not->toBe([]);

    // Without the alerts table the items still compute; only notices and
    // state go missing, and nothing is reconciled against them.
    $schema->drop('alerts');
    $withoutState = $controller->agenda(fleetOpsRadarDatabaseRequest())->getData(true);
    expect(array_keys($withoutState['sources']))->toBe(['inspectionLinks', 'notices', 'states']);
});

test('without a company in the session the brief neither reads nor writes a score history', function () {
    fleetOpsRadarDatabase();
    Carbon::setTestNow(Carbon::parse('2026-09-15 08:35:00', 'UTC'));
    session(['company' => null]);
    $controller = new FleetOpsRadarDatabaseController();

    $brief = $controller->briefing(fleetOpsRadarDatabaseRequest('GET', [], null))->getData(true);

    expect($brief['score']['delta'])->toBeNull()
        ->and(Fleetbase\Models\Setting::query()->count())->toBe(0);
});

test('a company with no drivers skips shifts and handover orders, on the real clock', function () {
    fleetOpsRadarDatabase();
    Carbon::setTestNow(Carbon::parse('2026-09-15 08:35:00', 'UTC'));
    session(['company' => 'company-empty']);

    $payload = (new RadarController())->agenda(fleetOpsRadarDatabaseRequest())->getData(true);

    expect($payload['handovers'])->toBe([])
        ->and($payload['lanes']['shifts'])->toBe([])
        ->and($payload['generated_at'])->toBe('2026-09-15T08:35:00+00:00');
});
