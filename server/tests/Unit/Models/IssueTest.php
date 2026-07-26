<?php

if (!function_exists('Fleetbase\FleetOps\Models\session')) {
    eval('namespace Fleetbase\FleetOps\Models; function session($key = null, $default = null) { return $key === "company" ? "company-issue" : $default; }');
}

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

class FleetOpsIssueModelDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }

    public function raw(string $value)
    {
        return $this->connection->raw($value);
    }
}

function fleetopsIssueModelUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsIssueModelDatabaseProbe($connection));
    app()->instance('db.schema', $connection->getSchemaBuilder());

    return $connection;
}

test('issue model exposes relation builders and activity log options', function () {
    fleetopsIssueModelUseInMemoryConnection();

    $issue = new Issue();

    expect($issue->reportedBy())->toBeInstanceOf(BelongsTo::class)
        ->and($issue->reportedBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($issue->reporter())->toBeInstanceOf(BelongsTo::class)
        ->and($issue->reporter()->getForeignKeyName())->toBe('reported_by_uuid')
        ->and($issue->assignedTo())->toBeInstanceOf(BelongsTo::class)
        ->and($issue->assignedTo()->getRelated())->toBeInstanceOf(User::class)
        ->and($issue->assignee())->toBeInstanceOf(BelongsTo::class)
        ->and($issue->assignee()->getForeignKeyName())->toBe('assigned_to_uuid')
        ->and($issue->vehicle())->toBeInstanceOf(BelongsTo::class)
        ->and($issue->vehicle()->getRelated())->toBeInstanceOf(Vehicle::class)
        ->and($issue->driver())->toBeInstanceOf(BelongsTo::class)
        ->and($issue->driver()->getRelated())->toBeInstanceOf(Driver::class)
        ->and($issue->order())->toBeInstanceOf(BelongsTo::class)
        ->and($issue->order()->getRelated())->toBeInstanceOf(Order::class)
        ->and($issue->files())->toBeInstanceOf(HasMany::class)
        ->and($issue->files()->getRelated())->toBeInstanceOf(File::class)
        ->and($issue->getActivitylogOptions()->logOnlyDirty)->toBeTrue()
        ->and($issue->getActivitylogOptions()->submitEmptyLogs)->toBeFalse();
});

test('issue mutators and display accessors normalize title status and missing related names', function () {
    fleetopsIssueModelUseInMemoryConnection();
    Carbon::setTestNow('2026-07-27 12:30:00');

    try {
        $issue             = new Issue();
        $issue->created_at = Carbon::parse('2026-07-01 14:45:00');
        $issue->title      = null;
        $issue->status     = 'Needs Review';

        expect($issue->title)->toBe('Issue reported on 01 Jul 26, 14:45')
            ->and($issue->status)->toBe('needs-review');

        $issue->title  = '  Loose cargo latch  ';
        $issue->status = null;

        $driver = new Driver();
        $driver->setRawAttributes(['name' => 'Alex Driver'], true);

        $vehicle = new Vehicle();
        $vehicle->setRawAttributes([
            'display_name' => 'Truck 42',
            'public_id'    => 'vehicle_42',
        ], true);

        $reporter = new User();
        $reporter->setRawAttributes([
            'name'      => 'Riley Reporter',
            'public_id' => 'user_reporter',
        ], true);

        $assignee = new User();
        $assignee->setRawAttributes([
            'name'      => 'Sam Assignee',
            'public_id' => 'user_assignee',
        ], true);

        $issue->setRelation('driver', $driver);
        $issue->setRelation('vehicle', $vehicle);
        $issue->setRelation('reporter', $reporter);
        $issue->setRelation('assignee', $assignee);

        expect($issue->title)->toBe('Loose cargo latch')
            ->and($issue->status)->toBe('pending')
            ->and($issue->relationLoaded('driver'))->toBeTrue()
            ->and($issue->relationLoaded('vehicle'))->toBeTrue()
            ->and($issue->relationLoaded('reporter'))->toBeTrue()
            ->and($issue->relationLoaded('assignee'))->toBeTrue()
            ->and($issue->driver_name)->toBeIn([null, ''])
            ->and($issue->vehicle_name)->toBeIn([null, ''])
            ->and($issue->vehicle_id)->toBe('vehicle_42')
            ->and($issue->reporter_name)->toBe('Riley Reporter')
            ->and($issue->reporter_id)->toBe('user_reporter')
            ->and($issue->assignee_name)->toBe('Sam Assignee')
            ->and($issue->assignee_id)->toBe('user_assignee');
    } finally {
        Carbon::setTestNow();
    }
});

test('issue import builds pending issue attributes from aliases without saving', function () {
    fleetopsIssueModelUseInMemoryConnection();

    $issue = Issue::createFromImport([
        'level'          => 'urgent',
        'details'        => 'Temperature sensor disconnected',
        'issue_category' => 'sensor',
        'issue_type'     => 'fault',
        'lat'            => '1.3001',
        'lng'            => '103.8002',
        'empty_field'    => null,
    ]);

    expect($issue)->toBeInstanceOf(Issue::class)
        ->and($issue->exists)->toBeFalse()
        ->and($issue->company_uuid)->toBe('company-issue')
        ->and($issue->priority)->toBe('urgent')
        ->and($issue->report)->toBe('Temperature sensor disconnected')
        ->and($issue->category)->toBe('sensor')
        ->and($issue->type)->toBe('fault')
        ->and($issue->status)->toBe('pending')
        ->and($issue->getAttributes()['location']->getValue(fleetopsIssueModelUseInMemoryConnection()->getQueryGrammar()))->toContain('POINT');
});
