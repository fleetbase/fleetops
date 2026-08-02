<?php

use Fleetbase\FleetOps\Events\OrderDispatchFailed;
use Fleetbase\FleetOps\Listeners\HandleOrderDispatchFailed;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Str;

/**
 * Covers the HandleOrderDispatchFailed listener's real helper bodies: the
 * order creator lookup against SQLite and the failure notification hand-off
 * through a notification dispatcher fake.
 */
class FleetOpsDispatchFailedEventStub extends OrderDispatchFailed
{
    public function __construct(private Order $order)
    {
        $this->reason = 'no driver available';
    }

    public function getModelRecord(): Order
    {
        return $this->order;
    }
}

function fleetopsDispatchFailedBoot(): SQLiteConnection
{
    if (!Str::hasMacro('humanize')) {
        Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Str::snake((string) $value)));
    }

    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
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
    $schema->create('users', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'name', 'email', 'status', 'type'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    session(['company' => 'company-1']);

    return $connection;
}

test('dispatch failed listener notifies the order creator when found', function () {
    $connection = fleetopsDispatchFailedBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'name' => 'Creator']);

    $dispatcher = new class implements Illuminate\Contracts\Notifications\Dispatcher {
        public array $sent = [];

        public function send($notifiables, $notification)
        {
            $this->sent[] = $notification;
        }

        public function sendNow($notifiables, $notification, ?array $channels = null)
        {
            $this->sent[] = $notification;
        }
    };
    app()->instance(Illuminate\Contracts\Notifications\Dispatcher::class, $dispatcher);

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-1', 'public_id' => 'order_test', 'created_by_uuid' => 'user-1', 'tracking' => 'TRK-1'], true);
    $order->exists = true;

    (new HandleOrderDispatchFailed())->handle(new FleetOpsDispatchFailedEventStub($order));
    expect($dispatcher->sent)->toHaveCount(1);

    // Unknown creators skip notification entirely
    $order->created_by_uuid = 'user-missing';
    (new HandleOrderDispatchFailed())->handle(new FleetOpsDispatchFailedEventStub($order));
    expect($dispatcher->sent)->toHaveCount(1);
});
