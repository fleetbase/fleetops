<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\WorkOrderController;
use Fleetbase\FleetOps\Models\WorkOrder;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the public API WorkOrderController protected helper bodies:
 * work order creation, resource wrappers, dispatch mail delivery and
 * the sent-activity log entry.
 */
if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Api\v1\activity')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Api\v1; function activity($logName = null) { $GLOBALS["fleetopsApiWorkOrderActivities"][] = $logName; return new class { public function performedOn($subject) { return $this; } public function withProperties(array $properties) { return $this; } public function log(string $message) { return true; } }; }');
}

class FleetOpsApiWorkOrderMailerFake
{
    public array $sent = [];
    public array $to   = [];

    public function to($users)
    {
        $this->to[] = $users;

        return $this;
    }

    public function send($mailable)
    {
        $this->sent[] = $mailable;

        return null;
    }
}

function fleetopsApiWorkOrderHelpersBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }
    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $c)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $schema->create('work_orders', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'created_by_uuid', 'assignee_uuid', 'assignee_type', 'vehicle_uuid', 'order_uuid', 'name', 'description', 'status', 'priority', 'type', 'scheduled_at', 'completed_at', 'meta', 'code', 'title', 'notes', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

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

    $mailer = new FleetOpsApiWorkOrderMailerFake();
    app()->instance('mail.manager', $mailer);
    Illuminate\Support\Facades\Mail::clearResolvedInstance('mail.manager');
    $GLOBALS['fleetopsApiWorkOrderMailer']     = $mailer;
    $GLOBALS['fleetopsApiWorkOrderActivities'] = [];

    return $connection;
}

test('work order helpers create records send dispatch mail and log activity', function () {
    $connection = fleetopsApiWorkOrderHelpersBoot();

    $controller = new WorkOrderController();
    $helper     = function (string $method, ...$arguments) use ($controller) {
        $reflection = new ReflectionMethod(WorkOrderController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, ...$arguments);
    };

    // Creation persists a work order row
    $workOrder = $helper('createWorkOrder', [
        'company_uuid' => 'company-1',
        'name'         => 'Brake Inspection',
        'status'       => 'created',
    ]);
    expect($workOrder)->toBeInstanceOf(WorkOrder::class)
        ->and($connection->table('work_orders')->count())->toBe(1)
        ->and((string) $connection->table('work_orders')->value('code'))->toStartWith('WO-');

    // Resource wrappers accept models and collections
    expect($helper('workOrderResource', $workOrder))->toBeInstanceOf(Fleetbase\FleetOps\Http\Resources\v1\WorkOrder::class)
        ->and($helper('workOrderResourceCollection', collect([$workOrder])))->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class);
    $deleted = $helper('deletedWorkOrderResource', $workOrder);
    expect($deleted)->not->toBeNull();

    // Dispatch mail goes through the mail manager
    $helper('sendWorkOrderDispatchedMail', 'vendor@example.test', $workOrder);
    expect($GLOBALS['fleetopsApiWorkOrderMailer']->sent)->toHaveCount(1)
        ->and($GLOBALS['fleetopsApiWorkOrderMailer']->sent[0])->toBeInstanceOf(Fleetbase\FleetOps\Mail\WorkOrderDispatched::class)
        ->and($GLOBALS['fleetopsApiWorkOrderMailer']->to)->toBe(['vendor@example.test']);

    // Sent activity records against the work order
    $helper('recordWorkOrderSentActivity', $workOrder, 'vendor@example.test');
    expect($GLOBALS['fleetopsApiWorkOrderActivities'])->toBe(['work_order_sent']);
});
