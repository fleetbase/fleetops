<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController;
use Illuminate\Http\Request;

/**
 * Covers the not-found branch of Internal\v1\OrderController::nextActivity().
 *
 * This branch used to depend on upstream behaviour that never fired: the
 * controller wrapped `Order::findByIdOrFail($id)` in a
 * `catch (ModelNotFoundException)`, but core-api's findByIdOrFail() raised a
 * BadMethodCallException that escaped the catch and surfaced as a 500.
 *
 * nextActivity() now resolves the order through the controller's own
 * company-scoped `findOrderById()` and returns the error response on a null
 * result, so the branch is live here regardless of the upstream release: an
 * unknown id and an id belonging to another company are reported identically.
 */
function fleetopsUpstreamNotFoundBoot(): Illuminate\Database\SQLiteConnection
{
    $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new Illuminate\Database\ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
    app()->instance('db', new class($connection) {
        public function __construct(public $c)
        {
        }

        public function connection($name = null)
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $connection->getSchemaBuilder()->create('orders', function ($blueprint) {
        $blueprint->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'order_config_uuid', 'status', 'type', '_key'] as $column) {
            $blueprint->string($column)->nullable();
        }
        $blueprint->timestamps();
        $blueprint->timestamp('deleted_at')->nullable();
    });

    session(['company' => 'company-upstream-1']);

    return $connection;
}

test('next activity reports a missing order instead of failing the request', function () {
    fleetopsUpstreamNotFoundBoot();

    // No order with this id exists, so findByIdOrFail must raise a
    // ModelNotFoundException that the controller catches and turns into an
    // error response — rather than an exception escaping as a 500
    $response = (new OrderController())->nextActivity(
        'order_does_not_exist',
        Request::create('/int/v1/orders/order_does_not_exist/next-activity', 'GET')
    );

    expect($response->getData(true))->toBe(['error' => 'No order found.']);
});
