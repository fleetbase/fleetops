<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController;
use Illuminate\Http\Request;

/**
 * Covers the not-found branch of Internal\v1\OrderController::nextActivity().
 *
 * ---------------------------------------------------------------------------
 * THIS FILE IS EXPECTED TO FAIL until fleetbase/core-api 1.6.55 is released and
 * pulled into server_vendor. Do not "fix" it by weakening the assertion.
 * ---------------------------------------------------------------------------
 *
 * The controller wraps `Order::findByIdOrFail($id)` in a
 * `catch (ModelNotFoundException)` that returns 'No order found.'. Today that
 * catch never fires: core-api's Model::findByIdOrFail() calls a
 * getModelNotFoundException() method that does not exist on Eloquent's builder,
 * so a missing order raises BadMethodCallException, escapes the catch, and
 * surfaces as a 500 instead of the intended error response.
 *
 * core-api#231 (branch dev-v1.6.55) replaces that with
 * `throw (new ModelNotFoundException())->setModel(static::class, [$identifier]);`
 * which makes this branch live. The assertion below states the post-fix
 * contract deliberately — asserting today's BadMethodCallException would
 * codify the defect instead of the intent.
 *
 * If CI must be green before that release lands, neutralise this file with a
 * single `->skip('pending fleetbase/core-api 1.6.55')` on the test below rather
 * than changing what it asserts.
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
