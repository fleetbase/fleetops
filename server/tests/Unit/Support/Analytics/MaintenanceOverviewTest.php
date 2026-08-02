<?php

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Support\Analytics\MaintenanceOverview;
use Fleetbase\Models\Company;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

class FleetOpsMaintenanceOverviewDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }
}

function fleetopsMaintenanceOverviewUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table maintenances (uuid varchar(64), company_uuid varchar(64), maintainable_uuid varchar(64) null, maintainable_type varchar(255) null, performed_by_uuid varchar(64) null, performed_by_type varchar(255) null, type varchar(64) null, status varchar(64), priority varchar(64) null, scheduled_at datetime null, completed_at datetime null, total_cost numeric null, currency varchar(8) null, deleted_at datetime null)');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsMaintenanceOverviewDatabaseProbe($connection));
    app()->instance('db.schema', $connection->getSchemaBuilder());

    return $connection;
}

function fleetopsMaintenanceOverviewCompany(): Company
{
    $company = new Company();
    $company->setRawAttributes([
        'uuid'     => 'company-maintenance-overview',
        'currency' => 'SGD',
    ], true);

    return $company;
}

test('maintenance overview queries overdue scheduled progress costs and upcoming work', function () {
    Carbon::setTestNow('2026-07-26 09:30:00');

    $connection = fleetopsMaintenanceOverviewUseInMemoryConnection();
    $connection->table('maintenances')->insert([
        [
            'uuid'                => 'overdue-scheduled',
            'company_uuid'        => 'company-maintenance-overview',
            'maintainable_uuid'   => null,
            'maintainable_type'   => null,
            'performed_by_uuid'   => null,
            'performed_by_type'   => null,
            'type'                => 'oil_change',
            'status'              => 'scheduled',
            'priority'            => 'high',
            'scheduled_at'        => '2026-07-20 10:00:00',
            'completed_at'        => null,
            'total_cost'          => null,
            'currency'            => 'SGD',
            'deleted_at'          => null,
        ],
        [
            'uuid'                => 'overdue-in-progress',
            'company_uuid'        => 'company-maintenance-overview',
            'maintainable_uuid'   => null,
            'maintainable_type'   => null,
            'performed_by_uuid'   => null,
            'performed_by_type'   => null,
            'type'                => 'inspection',
            'status'              => 'in_progress',
            'priority'            => 'urgent',
            'scheduled_at'        => '2026-07-25 08:00:00',
            'completed_at'        => null,
            'total_cost'          => null,
            'currency'            => 'SGD',
            'deleted_at'          => null,
        ],
        [
            'uuid'                => 'next-seven-pending',
            'company_uuid'        => 'company-maintenance-overview',
            'maintainable_uuid'   => null,
            'maintainable_type'   => null,
            'performed_by_uuid'   => null,
            'performed_by_type'   => null,
            'type'                => 'tire_rotation',
            'status'              => 'pending',
            'priority'            => 'medium',
            'scheduled_at'        => '2026-07-29 09:00:00',
            'completed_at'        => null,
            'total_cost'          => null,
            'currency'            => 'SGD',
            'deleted_at'          => null,
        ],
        [
            'uuid'                => 'next-seven-scheduled',
            'company_uuid'        => 'company-maintenance-overview',
            'maintainable_uuid'   => null,
            'maintainable_type'   => null,
            'performed_by_uuid'   => null,
            'performed_by_type'   => null,
            'type'                => 'brake_service',
            'status'              => 'scheduled',
            'priority'            => 'low',
            'scheduled_at'        => '2026-07-31 11:00:00',
            'completed_at'        => null,
            'total_cost'          => null,
            'currency'            => 'SGD',
            'deleted_at'          => null,
        ],
        [
            'uuid'                => 'completed-month',
            'company_uuid'        => 'company-maintenance-overview',
            'maintainable_uuid'   => null,
            'maintainable_type'   => null,
            'performed_by_uuid'   => null,
            'performed_by_type'   => null,
            'type'                => 'repair',
            'status'              => 'completed',
            'priority'            => 'normal',
            'scheduled_at'        => '2026-07-10 10:00:00',
            'completed_at'        => '2026-07-21 10:00:00',
            'total_cost'          => 1250.22,
            'currency'            => 'SGD',
            'deleted_at'          => null,
        ],
        [
            'uuid'                => 'completed-year',
            'company_uuid'        => 'company-maintenance-overview',
            'maintainable_uuid'   => null,
            'maintainable_type'   => null,
            'performed_by_uuid'   => null,
            'performed_by_type'   => null,
            'type'                => 'repair',
            'status'              => 'completed',
            'priority'            => 'normal',
            'scheduled_at'        => '2026-03-10 10:00:00',
            'completed_at'        => '2026-03-12 10:00:00',
            'total_cost'          => 300.33,
            'currency'            => 'SGD',
            'deleted_at'          => null,
        ],
        [
            'uuid'                => 'wrong-currency',
            'company_uuid'        => 'company-maintenance-overview',
            'maintainable_uuid'   => null,
            'maintainable_type'   => null,
            'performed_by_uuid'   => null,
            'performed_by_type'   => null,
            'type'                => 'repair',
            'status'              => 'completed',
            'priority'            => 'normal',
            'scheduled_at'        => '2026-07-11 10:00:00',
            'completed_at'        => '2026-07-22 10:00:00',
            'total_cost'          => 9999.99,
            'currency'            => 'USD',
            'deleted_at'          => null,
        ],
        [
            'uuid'                => 'canceled-overdue-ignored',
            'company_uuid'        => 'company-maintenance-overview',
            'maintainable_uuid'   => null,
            'maintainable_type'   => null,
            'performed_by_uuid'   => null,
            'performed_by_type'   => null,
            'type'                => 'inspection',
            'status'              => 'canceled',
            'priority'            => 'normal',
            'scheduled_at'        => '2026-07-19 10:00:00',
            'completed_at'        => null,
            'total_cost'          => null,
            'currency'            => 'SGD',
            'deleted_at'          => null,
        ],
        [
            'uuid'                => 'other-company-ignored',
            'company_uuid'        => 'other-company',
            'maintainable_uuid'   => null,
            'maintainable_type'   => null,
            'performed_by_uuid'   => null,
            'performed_by_type'   => null,
            'type'                => 'inspection',
            'status'              => 'scheduled',
            'priority'            => 'critical',
            'scheduled_at'        => '2026-07-27 10:00:00',
            'completed_at'        => null,
            'total_cost'          => null,
            'currency'            => 'SGD',
            'deleted_at'          => null,
        ],
    ]);

    $result = MaintenanceOverview::forCompany(fleetopsMaintenanceOverviewCompany())->get();

    expect($result)->toMatchArray([
        'overdue'           => 2,
        'scheduled_next_7d' => 2,
        'in_progress'       => 1,
        'cost_this_month'   => 1250.22,
        'cost_ytd'          => 1550.55,
        'currency'          => 'SGD',
    ])
        ->and(array_map(fn (array $item) => [
            'uuid'         => $item['uuid'],
            'type'         => $item['type'],
            'priority'     => $item['priority'],
            'scheduled_at' => $item['scheduled_at']->toDateTimeString(),
        ], $result['upcoming']))->toBe([
            [
                'uuid'         => 'next-seven-pending',
                'type'         => 'tire_rotation',
                'priority'     => 'medium',
                'scheduled_at' => '2026-07-29 09:00:00',
            ],
            [
                'uuid'         => 'next-seven-scheduled',
                'type'         => 'brake_service',
                'priority'     => 'low',
                'scheduled_at' => '2026-07-31 11:00:00',
            ],
        ]);

    Carbon::setTestNow();
});
