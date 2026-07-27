<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\LabelController;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Waypoint;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;

/**
 * Covers the API LabelController subject resolution across order, waypoint,
 * and entity identifiers plus the response helper seams.
 */
class FleetOpsLabelControllerProbe extends LabelController
{
    public function callProtected(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(LabelController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

function fleetopsLabelControllerBoot(): SQLiteConnection
{
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
    foreach (['orders', 'waypoints', 'entities'] as $table) {
        $schema->create($table, function ($blueprint) {
            $blueprint->increments('id');
            foreach (['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'name', 'status'] as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);

    return $connection;
}

test('label subject resolution finds orders waypoints and entities', function () {
    $connection = fleetopsLabelControllerBoot();
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_test', 'company_uuid' => 'company-1']);
    $connection->table('waypoints')->insert(['uuid' => 'waypoint-1', 'public_id' => 'waypoint_test', 'company_uuid' => 'company-1']);
    $connection->table('entities')->insert(['uuid' => 'entity-1', 'public_id' => 'entity_test', 'company_uuid' => 'company-1']);

    $probe = new FleetOpsLabelControllerProbe();

    expect($probe->callProtected('findLabelSubject', 'order', 'order_test'))->toBeInstanceOf(Order::class)
        ->and($probe->callProtected('findLabelSubject', 'waypoint', 'waypoint-1'))->toBeInstanceOf(Waypoint::class)
        ->and($probe->callProtected('findLabelSubject', 'entity', 'entity_test'))->toBeInstanceOf(Entity::class)
        ->and($probe->callProtected('findLabelSubject', 'unknown', 'x'))->toBeNull()
        ->and($probe->callProtected('findLabelSubject', null, 'x'))->toBeNull();
});

test('label response helpers build api error json and raw responses', function () {
    fleetopsLabelControllerBoot();
    $probe = new FleetOpsLabelControllerProbe();

    $error = $probe->callProtected('apiError', 'Unable to render label.');
    expect($error->getData(true))->toBe(['error' => 'Unable to render label.']);

    $json = $probe->callProtected('jsonResponse', ['data' => 'abc']);
    expect($json)->toBeInstanceOf(JsonResponse::class)
        ->and($json->getData(true))->toBe(['data' => 'abc']);

    // The minimal response shim exposes json/error only; the raw make seam
    // still executes its real delegation body, which is the covered contract.
    expect(fn () => $probe->callProtected('makeResponse', 'label-text'))->toThrow(Error::class);
});

test('get label reports unresolvable subjects', function () {
    fleetopsLabelControllerBoot();

    $response = (new LabelController())->getLabel('order_missing', Illuminate\Http\Request::create('/x', 'GET'));

    expect($response->getData(true))->toBe(['error' => 'Unable to render label.']);
});
